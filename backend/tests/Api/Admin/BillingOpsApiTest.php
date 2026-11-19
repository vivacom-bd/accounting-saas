<?php

declare(strict_types=1);

use App\Domain\Admin\AdminRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AdminApi;
use Tests\Support\BillingScenario;

uses(AdminApi::class);

/** An offline invoice row (no Stripe id) on the platform mirror. */
function boInvoice(string $tenant, int $total, array $over = []): string
{
    $id = (string) Str::uuid7();
    DB::connection('pgsql_platform')->table('stripe_invoices')->insert($over + [
        'id' => $id, 'tenant_id' => $tenant, 'stripe_id' => null, 'source' => 'offline', 'number' => 'OFF-1',
        'status' => 'open', 'currency' => 'USD', 'subtotal' => $total, 'tax' => 0, 'total' => $total,
        'amount_paid' => 0, 'amount_refunded' => 0, 'lines' => '[]', 'dunning_stage' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('a refund needs a second staff member, then records the refund', function (): void {
    $tenant = BillingScenario::tenant(['lifecycle' => 'active']);
    $invoice = boInvoice($tenant, 1000);
    $this->asAdmin(AdminRole::Finance);

    $approval = $this->postJson("/api/admin/billing/invoices/{$invoice}/refund", ['amount' => 400, 'reason' => 'Goodwill'])
        ->assertStatus(202)->assertJsonPath('data.kind', 'refund')->assertJsonPath('data.status', 'pending')->json('data.id');
    expect((int) DB::connection('pgsql_platform')->table('stripe_invoices')->where('id', $invoice)->value('amount_refunded'))->toBe(0);

    // A different owner approves it; the executor records the refund.
    $this->asAdmin(AdminRole::Owner);
    $this->postJson("/api/admin/approvals/{$approval}/approve")->assertOk()->assertJsonPath('data.status', 'executed');

    expect((int) DB::connection('pgsql_platform')->table('stripe_invoices')->where('id', $invoice)->value('amount_refunded'))->toBe(400)
        ->and(DB::connection('pgsql_platform')->table('admin_audit_events')->where('action', 'billing.refunded')->count())->toBe(1);
});

it('refuses a refund larger than what is still refundable', function (): void {
    $tenant = BillingScenario::tenant(['lifecycle' => 'active']);
    $invoice = boInvoice($tenant, 1000);
    $this->asAdmin(AdminRole::Finance);

    $this->postJson("/api/admin/billing/invoices/{$invoice}/refund", ['amount' => 1200, 'reason' => 'x'])
        ->assertStatus(422)->assertJsonPath('code', 'invoice_adjustment_invalid');
    $this->postJson("/api/admin/billing/invoices/{$invoice}/refund", ['amount' => 0, 'reason' => 'x'])->assertStatus(422);
});

it('marking an offline invoice paid reactivates a past-due organisation', function (): void {
    $tenant = BillingScenario::tenant(['lifecycle' => 'past_due', 'delinquent_since' => '2026-03-01 00:00:00+00']);
    $invoice = boInvoice($tenant, 5000);
    $this->asAdmin(AdminRole::Finance);

    $this->postJson("/api/admin/billing/invoices/{$invoice}/mark-paid")->assertOk()->assertJsonPath('data.status', 'paid');

    expect(DB::connection('pgsql_platform')->table('stripe_invoices')->where('id', $invoice)->value('status'))->toBe('paid')
        ->and(DB::connection('pgsql_platform')->table('tenants')->where('id', $tenant)->value('lifecycle'))->toBe('active');
});

it('groups the tax report by country, currency and month', function (): void {
    $ke = BillingScenario::tenant(['lifecycle' => 'active']);
    $ug = BillingScenario::tenant(['lifecycle' => 'active']);
    DB::connection('pgsql_platform')->table('tenants')->where('id', $ke)->update(['country' => 'KE']);
    DB::connection('pgsql_platform')->table('tenants')->where('id', $ug)->update(['country' => 'UG']);
    foreach ([[$ke, 'USD', 1000, 160], [$ug, 'USD', 2000, 320]] as [$tenant, $currency, $net, $tax]) {
        boInvoice($tenant, $net + $tax, ['status' => 'paid', 'currency' => $currency, 'subtotal' => $net, 'tax' => $tax, 'paid_at' => '2026-03-10 10:00:00+00', 'created_at' => '2026-03-10 10:00:00+00']);
    }
    $this->asAdmin(AdminRole::Finance);

    $rows = collect($this->getJson('/api/admin/billing/tax-report?from=2026-03-01&to=2026-03-31')->assertOk()->json('data'));
    $kenya = $rows->firstWhere('country', 'KE');
    $uganda = $rows->firstWhere('country', 'UG');
    expect($kenya)->toMatchArray(['currency' => 'USD', 'month' => '2026-03', 'net' => 1000, 'tax' => 160, 'gross' => 1160])
        ->and($uganda['gross'])->toBe(2320);
});

it('recognises revenue for the requested month from paid invoice lines', function (): void {
    $tenant = BillingScenario::tenant(['lifecycle' => 'active']);
    $lines = json_encode([[
        'description' => 'Annual plan', 'amount' => 120000, 'period_start' => '2026-01-01T00:00:00+00:00',
        'period_end' => '2027-01-01T00:00:00+00:00', 'plan_code' => 'pro',
    ]], JSON_THROW_ON_ERROR);
    boInvoice($tenant, 120000, ['status' => 'paid', 'lines' => $lines]);
    $this->asAdmin(AdminRole::Finance);

    $rows = collect($this->getJson('/api/admin/billing/revenue-recognition?month=2026-01')->assertOk()->json('data'));
    expect($rows)->toHaveCount(1)->and($rows[0]['recognised'])->toBe(10191);

    $csv = $this->get('/api/admin/billing/revenue-recognition.csv?month=2026-01')->assertOk();
    expect($csv->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv->streamedContent())->toContain('Annual plan')->toContain('101.91');
});

it('lists invoices with filters and gates on billing.view', function (): void {
    $tenant = BillingScenario::tenant(['lifecycle' => 'active']);
    boInvoice($tenant, 1000);
    $this->asAdmin(AdminRole::Readonly);

    $this->getJson("/api/admin/billing/invoices?tenant={$tenant}")->assertOk()->assertJsonPath('data.0.tenant_id', $tenant);

    $this->asAdmin(AdminRole::Support);
    $this->getJson('/api/admin/billing/invoices')->assertForbidden();
});
