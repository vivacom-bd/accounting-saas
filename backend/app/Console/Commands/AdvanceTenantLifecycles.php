<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Platform\ChangeTenantLifecycle;
use App\Contracts\Clock;
use App\Domain\Platform\LifecycleState;
use App\Domain\Platform\TenantLifecycle;
use App\Domain\Shared\DomainRuleViolation;
use App\Support\Actor;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Applies every lifecycle step that has come due (plan Task 5, G5/G6), each dated at its own due instant, so a tenant
 * first seen long after its trial ended catches up in one run (Review Focus 4). Idempotent: once applied, a step is no
 * longer due. Keyset-paged over the platform tenant directory; only tenants in a state with a clock are read.
 */
final class AdvanceTenantLifecycles extends Command
{
    private const array CLOCKED = ['trial', 'past_due', 'read_only', 'suspended'];

    private const array REASONS = ['read_only' => 'lifecycle.read_only_due', 'suspended' => 'lifecycle.suspended_due', 'cancelled' => 'lifecycle.cancelled_due'];

    protected $description = 'Move tenants whose trial or payment grace has run out to their next lifecycle state';

    protected $signature = 'lifecycle:advance {--page=500 : Tenants per keyset page}';

    public function handle(ChangeTenantLifecycle $change, Clock $clock): int
    {
        $page = max(1, (int) $this->option('page'));
        $now = $clock->now();
        $db = DB::connection(AdminTransaction::CONNECTION);
        $after = '00000000-0000-0000-0000-000000000000';
        $moved = 0;

        do {
            $rows = $db->table('tenants')
                ->select(['id', 'lifecycle', 'trial_ends_at', 'delinquent_since', 'lifecycle_since'])
                ->whereIn('lifecycle', self::CLOCKED)
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit($page)
                ->get();

            foreach ($rows as $row) {
                $after = (string) $row->id;
                $state = LifecycleState::from((string) $row->lifecycle);
                $steps = TenantLifecycle::due($state, self::time($row->trial_ends_at), self::time($row->delinquent_since), self::time($row->lifecycle_since), $now);

                // A bounded list per tenant: the loop ends whatever happens to the tenant meanwhile.
                foreach ($steps as $step) {
                    try {
                        $change->handle(Actor::system($after), $after, $step['to'], self::REASONS[$step['to']->value] ?? 'lifecycle.due', $step['at'], $state);
                    } catch (DomainRuleViolation $e) {
                        // Changed since we read it (paid, moved by staff): leave the rest for the next run.
                        $this->warn("Tenant {$after}: {$e->getMessage()}");

                        break;
                    }
                    $state = $step['to'];
                    $moved++;
                }
            }
        } while ($rows->count() === $page);

        $this->info("Lifecycle steps applied: {$moved}");

        return self::SUCCESS;
    }

    private static function time(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable((string) $value);
    }
}
