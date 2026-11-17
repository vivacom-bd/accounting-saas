<?php

declare(strict_types=1);

use App\Actions\Admin\Plans\ArchiveCoupon;
use App\Actions\Admin\Plans\ArchivePlan;
use App\Actions\Admin\Plans\CreateCoupon;
use App\Actions\Admin\Plans\CreatePlan;
use App\Actions\Admin\Plans\PublishPlanVersion;
use App\Actions\Admin\Plans\UpsertAddon;
use App\Domain\Admin\AdminRole;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Admin\AdminUser;
use App\Models\Billing\Coupon;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanAddon;
use App\Models\Billing\PlanVersion;
use App\Support\Admin\AdminActor;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function planActor(): AdminActor
{
    $admin = AdminUser::query()->create([
        'id' => (string) Str::uuid7(), 'email' => 'fin@ledgerly.test', 'name' => 'Fin', 'password' => 'x', 'role' => AdminRole::Finance,
    ]);

    return new AdminActor($admin->id, AdminRole::Finance, '127.0.0.1', 'pest', 'req-1');
}

/** @return array{prices: array<string, mixed>, limits: array<string, mixed>, entitlements: array<string, mixed>} */
function planSpec(int $usd = 4900): array
{
    return [
        'prices' => ['USD' => ['monthly' => $usd, 'annual' => $usd * 10], 'KES' => ['monthly' => $usd * 130, 'annual' => $usd * 1300], 'EUR' => ['monthly' => $usd, 'annual' => $usd * 10]],
        'limits' => ['companies' => 1, 'users' => 3, 'api_clients' => 1, 'journal_lines_per_month' => 1000, 'storage_mb' => 2048, 'api_calls_per_day' => null],
        'entitlements' => ['multi_currency' => false, 'independent_approvals' => false, 'fixed_assets' => false, 'budgets' => false,
            'api_access' => true, 'consolidation' => false, 'audit_chain_export' => true, 'custom_roles' => false],
    ];
}

function planSqlState(Closure $fn): ?string
{
    try {
        $fn();
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

function createStarter(AdminActor $actor, int $usd = 4900): Plan
{
    $spec = planSpec($usd);

    return app(CreatePlan::class)($actor, 'starter', 'Starter', 'Essentials', 1, $spec['prices'], $spec['limits'], $spec['entitlements'], 14, null);
}

it('creates a plan with its first version and audits it', function (): void {
    $actor = planActor();
    $plan = createStarter($actor);

    $version = $plan->versions()->sole();
    expect($plan->code)->toBe('starter')->and($plan->is_active)->toBeTrue()
        ->and($version->version)->toBe(1)->and($version->prices['USD']['monthly'])->toBe(4900)
        ->and($version->limits['api_calls_per_day'])->toBeNull()->and($version->created_by)->toBe($actor->id);

    $event = DB::connection('pgsql_platform')->table('admin_audit_events')->where('action', 'plan.created')->sole();
    expect($event->subject_id)->toBe($plan->id)->and($event->admin_id)->toBe($actor->id);
});

it('publishing creates new version and keeps old immutable', function (): void {
    $actor = planActor();
    $plan = createStarter($actor);
    $spec = planSpec(5900);

    $v2 = app(PublishPlanVersion::class)($actor, $plan, $spec['prices'], $spec['limits'], $spec['entitlements'], 14, null);

    expect($v2->version)->toBe(2)->and($plan->versions()->count())->toBe(2);
    $v1 = $plan->versions()->where('version', 1)->sole();
    // Effective versions cannot be edited or removed, not even by the platform role.
    expect(planSqlState(fn () => DB::connection('pgsql_platform')->table('plan_versions')->where('id', $v1->id)->update(['prices' => '{}'])))->toBe('LG011')
        ->and(planSqlState(fn () => DB::connection('pgsql_platform')->table('plan_versions')->where('id', $v1->id)->update(['limits' => '{}'])))->toBe('LG011')
        ->and(planSqlState(fn () => DB::connection('pgsql_platform')->table('plan_versions')->where('id', $v1->id)->delete()))->toBe('LG011')
        ->and(PlanVersion::query()->find($v1->id)?->prices['USD']['monthly'])->toBe(4900);
    // Bookkeeping columns stay writable (Stripe ids, Task 11).
    expect(DB::connection('pgsql_platform')->table('plan_versions')->where('id', $v1->id)->update(['stripe_product_id' => 'prod_x']))->toBe(1);

    expect(DB::connection('pgsql_platform')->table('admin_audit_events')->where('action', 'plan.version_published')->count())->toBe(1);
});

it('a scheduled version can still be corrected until it takes effect', function (): void {
    $actor = planActor();
    $plan = createStarter($actor);
    $spec = planSpec(5900);
    $future = new DateTimeImmutable('+10 days');

    $v2 = app(PublishPlanVersion::class)($actor, $plan, $spec['prices'], $spec['limits'], $spec['entitlements'], 14, $future);

    expect($v2->effective_from > now()->toDateTimeImmutable())->toBeTrue()
        ->and(DB::connection('pgsql_platform')->table('plan_versions')->where('id', $v2->id)->update(['trial_days' => 7]))->toBe(1);
});

it('refuses an effective date in the past', function (): void {
    $actor = planActor();
    $plan = createStarter($actor);
    $spec = planSpec();

    expect(fn () => app(PublishPlanVersion::class)($actor, $plan, $spec['prices'], $spec['limits'], $spec['entitlements'], 14, $this->clock->now()->modify('-3 days')))
        ->toThrow(DomainRuleViolation::class);
});

it('refuses unknown limit or entitlement keys and missing ones', function (): void {
    $actor = planActor();
    $spec = planSpec();
    $badLimits = $spec['limits'] + ['seats' => 5];
    $missing = $spec['entitlements'];
    unset($missing['budgets']);
    $stringLimit = ['companies' => '3'] + $spec['limits'];

    foreach ([[$badLimits, $spec['entitlements']], [$spec['limits'], $missing], [$stringLimit, $spec['entitlements']]] as [$limits, $ent]) {
        expect(fn () => app(CreatePlan::class)($actor, 'x'.Str::lower(Str::random(4)), 'X', null, 1, $spec['prices'], $limits, $ent, 14, null))
            ->toThrow(DomainRuleViolation::class);
    }
    expect(Plan::query()->count())->toBe(0);
});

it('refuses a duplicate plan code', function (): void {
    $actor = planActor();
    createStarter($actor);

    expect(fn () => createStarter($actor))->toThrow(DomainRuleViolation::class);
});

it('archiving a plan keeps its versions', function (): void {
    $actor = planActor();
    $plan = createStarter($actor);

    app(ArchivePlan::class)($actor, $plan);

    expect($plan->fresh()?->is_active)->toBeFalse()->and($plan->versions()->count())->toBe(1);
    expect(DB::connection('pgsql_platform')->table('admin_audit_events')->where('action', 'plan.archived')->count())->toBe(1);
});

it('upserts add-ons by code', function (): void {
    $actor = planActor();
    $price = ['USD' => 2500, 'KES' => 325000, 'EUR' => 2300];

    $a = app(UpsertAddon::class)($actor, 'extra_company', 'Extra company', 'company', $price, 'company / month');
    $b = app(UpsertAddon::class)($actor, 'extra_company', 'Extra legal entity', 'company', ['USD' => 2600] + $price, 'company / month');

    expect(PlanAddon::query()->count())->toBe(1)->and($b->id)->toBe($a->id)
        ->and($b->name)->toBe('Extra legal entity')->and($b->price['USD'])->toBe(2600);
    expect(fn () => app(UpsertAddon::class)($actor, 'bad', 'Bad', 'company', ['USD' => 25.5], 'x'))->toThrow(DomainRuleViolation::class);
    expect(fn () => app(UpsertAddon::class)($actor, 'bad', 'Bad', 'seat', $price, 'x'))->toThrow(DomainRuleViolation::class);
    expect(DB::connection('pgsql_platform')->table('admin_audit_events')->whereIn('action', ['addon.created', 'addon.updated'])->count())->toBe(2);
});

it('coupon needs percent or amount not both', function (): void {
    $actor = planActor();
    $make = fn (array $over) => app(CreateCoupon::class)($actor, [
        'code' => 'LAUNCH20', 'percent' => 20, 'amount' => null, 'currency' => null, 'duration' => 'once', 'duration_months' => null,
        'restrictions' => [], 'max_redemptions' => null, 'expires_at' => null, ...$over,
    ]);

    expect(fn () => $make(['amount' => 500, 'currency' => 'USD']))->toThrow(DomainRuleViolation::class)
        ->and(fn () => $make(['percent' => null]))->toThrow(DomainRuleViolation::class)
        ->and(fn () => $make(['percent' => 0]))->toThrow(DomainRuleViolation::class)
        ->and(fn () => $make(['percent' => 101]))->toThrow(DomainRuleViolation::class)
        ->and(fn () => $make(['percent' => null, 'amount' => 500]))->toThrow(DomainRuleViolation::class)
        ->and(fn () => $make(['percent' => null, 'amount' => 500, 'currency' => 'GBP']))->toThrow(DomainRuleViolation::class)
        ->and(fn () => $make(['duration' => 'repeating']))->toThrow(DomainRuleViolation::class)
        ->and(fn () => $make(['duration' => 'once', 'duration_months' => 3]))->toThrow(DomainRuleViolation::class)
        ->and(fn () => $make(['duration' => 'weekly']))->toThrow(DomainRuleViolation::class)
        ->and(fn () => $make(['max_redemptions' => 0]))->toThrow(DomainRuleViolation::class)
        ->and(Coupon::query()->count())->toBe(0);

    $percent = $make(['code' => 'launch20']);
    $amount = $make(['code' => 'FIVEOFF', 'percent' => null, 'amount' => 500, 'currency' => 'USD', 'duration' => 'repeating', 'duration_months' => 3, 'max_redemptions' => 10]);

    expect($percent->code)->toBe('LAUNCH20')->and($percent->percent)->toBe(20)->and($amount->amount)->toBe(500)->and($amount->duration_months)->toBe(3);
    // Codes are unique regardless of case.
    expect(fn () => $make(['code' => 'Launch20']))->toThrow(DomainRuleViolation::class);
});

it('the database also refuses a coupon with both percent and amount', function (): void {
    $row = ['id' => (string) Str::uuid7(), 'code' => 'BOTH', 'percent' => 10, 'amount' => 5, 'currency' => 'USD', 'duration' => 'once', 'created_at' => now(), 'updated_at' => now()];

    expect(planSqlState(fn () => DB::connection('pgsql_platform')->table('coupons')->insert($row)))->toBe('23514');
});

it('archives a coupon and keeps it', function (): void {
    $actor = planActor();
    $coupon = app(CreateCoupon::class)($actor, [
        'code' => 'LAUNCH20', 'percent' => 20, 'amount' => null, 'currency' => null, 'duration' => 'forever', 'duration_months' => null,
        'restrictions' => ['plan_codes' => ['starter']], 'max_redemptions' => 5, 'expires_at' => null,
    ]);

    app(ArchiveCoupon::class)($actor, $coupon);

    expect($coupon->fresh()?->is_active)->toBeFalse()->and($coupon->fresh()?->restrictions['plan_codes'])->toBe(['starter']);
});

it('seeder is idempotent', function (): void {
    PlanSeeder::sync(DB::connection('pgsql_platform'));
    $snapshot = fn () => [
        Plan::query()->orderBy('sort')->pluck('code')->all(),
        PlanVersion::query()->count(),
        PlanAddon::query()->orderBy('code')->pluck('code')->all(),
        PlanVersion::query()->orderBy('id')->pluck('id')->all(),
    ];
    $first = $snapshot();

    PlanSeeder::sync(DB::connection('pgsql_platform'));

    expect($snapshot())->toBe($first)
        ->and($first[0])->toBe(['starter', 'professional', 'business', 'enterprise'])
        ->and($first[1])->toBe(4)
        ->and($first[2])->toBe(['extra_company', 'metered_api', 'storage_50gb', 'user_pack_5']);

    $pro = Plan::query()->where('code', 'professional')->sole()->versions()->sole();
    $ent = Plan::query()->where('code', 'enterprise')->sole()->versions()->sole();
    expect($pro->prices['USD'])->toEqual(['monthly' => 14900, 'annual' => 143000])
        ->and($pro->limits['users'])->toBe(8)->and($pro->entitlements['fixed_assets'])->toBeTrue()
        ->and($ent->prices['USD'])->toEqual(['monthly' => null, 'annual' => null]);
});
