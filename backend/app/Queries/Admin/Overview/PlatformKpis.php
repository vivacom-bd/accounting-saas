<?php

declare(strict_types=1);

namespace App\Queries\Admin\Overview;

use App\Contracts\Clock;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;
use DateTimeZone;
use stdClass;

/**
 * The operator dashboard (`GET /api/admin/overview?months=12`, plan Task 19, G9). Every money figure is an integer in
 * minor units of its own currency; there is never a blended total. MRR is normalised the same way the Actions record it
 * (a yearly amount is one twelfth, `MrrMovement::monthly`), so the dashboard and `subscription_events` agree.
 *
 * Movements come from `subscription_events` (new, expansion, contraction, churn, reactivation) grouped by month and
 * currency. Cohort retention counts tenants created in a month that had not reached `cancelled` by the end of each
 * later month, read from `tenant_lifecycle_events`; it is a best-effort view, not an accounting figure.
 */
final class PlatformKpis
{
    private const array REVENUE_STATUSES = ['active', 'past_due'];

    public function __construct(private readonly Clock $clock) {}

    /** @return array<string, mixed> */
    public function __invoke(int $months = 12): array
    {
        $months = max(1, min(24, $months));
        $now = $this->clock->now();
        $from = $now->modify('-'.($months - 1).' months')->modify('first day of this month')->setTime(0, 0);

        $mrr = $this->mrrByCurrency();
        $arr = array_map(fn (array $row): array => ['currency' => $row['currency'], 'amount' => $row['amount'] * 12], $mrr);

        return [
            'mrr' => $mrr,
            'arr' => $arr,
            'movements' => $this->movements($from),
            'active_tenants' => $this->activeTenants(),
            'trials' => $this->trials(),
            'trial_conversion_bp' => $this->conversionBasisPoints(),
            'failed_payments' => $this->failedPayments(),
            'signups_today' => $this->signupsToday(),
            'revenue_by_plan' => $this->revenueByPlan(),
            'geography' => $this->geography(),
            'cohorts' => $this->cohorts($months),
            'open_items' => $this->openItems(),
        ];
    }

    /** @return list<array{currency: string, amount: int}> */
    private function mrrByCurrency(): array
    {
        $rows = AdminTransaction::connection()->select(<<<'SQL'
            SELECT s.currency, COALESCE(SUM(x.monthly), 0)::bigint AS amount
            FROM subscriptions s
            JOIN plan_versions pv ON pv.id = s.plan_version_id
            CROSS JOIN LATERAL (
                SELECT CASE WHEN s.interval = 'year'
                    THEN (COALESCE(s.custom_amount, NULLIF(pv.prices -> s.currency ->> 'annual', '')::bigint) * s.quantity + 6) / 12
                    ELSE COALESCE(s.custom_amount, NULLIF(pv.prices -> s.currency ->> 'monthly', '')::bigint) * s.quantity
                END AS monthly
            ) x
            WHERE s.status IN ('active', 'past_due')
            GROUP BY s.currency
            ORDER BY s.currency
            SQL);

        return array_values(array_map(fn (stdClass $r): array => ['currency' => (string) $r->currency, 'amount' => (int) $r->amount], $rows));
    }

    /**
     * One row per (month, currency) with the four movement buckets summed from the recorded deltas.
     *
     * @return list<array{month: string, currency: string, new: int, expansion: int, contraction: int, churn: int, reactivation: int}>
     */
    private function movements(DateTimeImmutable $from): array
    {
        $rows = AdminTransaction::connection()->select(<<<'SQL'
            SELECT to_char(date_trunc('month', occurred_at AT TIME ZONE 'UTC'), 'YYYY-MM') AS month,
                   currency,
                   COALESCE(SUM(mrr_delta) FILTER (WHERE kind = 'new'), 0)::bigint AS new,
                   COALESCE(SUM(mrr_delta) FILTER (WHERE kind = 'expansion'), 0)::bigint AS expansion,
                   COALESCE(SUM(mrr_delta) FILTER (WHERE kind = 'contraction'), 0)::bigint AS contraction,
                   COALESCE(SUM(mrr_delta) FILTER (WHERE kind = 'churn'), 0)::bigint AS churn,
                   COALESCE(SUM(mrr_delta) FILTER (WHERE kind = 'reactivation'), 0)::bigint AS reactivation
            FROM subscription_events
            WHERE occurred_at >= ?
            GROUP BY 1, 2
            ORDER BY 1, 2
            SQL, [$from->format('Y-m-d H:i:sP')]);

        return array_values(array_map(fn (stdClass $r): array => [
            'month' => (string) $r->month, 'currency' => (string) $r->currency,
            'new' => (int) $r->new, 'expansion' => (int) $r->expansion, 'contraction' => (int) $r->contraction,
            'churn' => (int) $r->churn, 'reactivation' => (int) $r->reactivation,
        ], $rows));
    }

    private function activeTenants(): int
    {
        return (int) AdminTransaction::connection()->table('tenants')->whereIn('lifecycle', self::REVENUE_STATUSES)->count();
    }

    private function trials(): int
    {
        return (int) AdminTransaction::connection()->table('tenants')->where('lifecycle', 'trial')->count();
    }

    /** Among trials that started in the last 90 days, the share that has since become paying (basis points). */
    private function conversionBasisPoints(): int
    {
        $db = AdminTransaction::connection();
        $since = $this->clock->now()->modify('-90 days')->format('Y-m-d H:i:sP');
        /** @var stdClass|null $row */
        $row = $db->selectOne(<<<'SQL'
            WITH started AS (
                SELECT DISTINCT tenant_id FROM tenant_lifecycle_events
                WHERE to_status = 'trial' AND occurred_at >= ?
            ), converted AS (
                SELECT DISTINCT tenant_id FROM tenant_lifecycle_events
                WHERE to_status IN ('active', 'past_due') AND tenant_id IN (SELECT tenant_id FROM started)
            )
            SELECT (SELECT count(*) FROM started) AS started, (SELECT count(*) FROM converted) AS converted
            SQL, [$since]);

        $started = $row === null ? 0 : (int) $row->started;

        return $started === 0 ? 0 : (int) round(((int) $row->converted / $started) * 10000);
    }

    /** @return array{count: int, by_currency: list<array{currency: string, amount: int}>} */
    private function failedPayments(): array
    {
        $rows = AdminTransaction::connection()->select(<<<'SQL'
            SELECT currency, COALESCE(SUM(total - amount_paid), 0)::bigint AS amount
            FROM stripe_invoices
            WHERE status = 'open' AND due_at IS NOT NULL AND due_at < now()
            GROUP BY currency
            ORDER BY currency
            SQL);

        $by = array_values(array_map(fn (stdClass $r): array => ['currency' => (string) $r->currency, 'amount' => (int) $r->amount], $rows));
        $count = (int) AdminTransaction::connection()->table('stripe_invoices')->where('status', 'open')->whereNotNull('due_at')->where('due_at', '<', $this->clock->now())->count();

        return ['count' => $count, 'by_currency' => $by];
    }

    private function signupsToday(): int
    {
        $today = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');

        return (int) AdminTransaction::connection()->table('tenants')->whereRaw("(created_at AT TIME ZONE 'UTC')::date = ?", [$today])->count();
    }

    /** @return list<array{plan_code: string, currency: string, mrr: int}> */
    private function revenueByPlan(): array
    {
        $rows = AdminTransaction::connection()->select(<<<'SQL'
            SELECT p.code AS plan_code, s.currency, COALESCE(SUM(x.monthly), 0)::bigint AS mrr
            FROM subscriptions s
            JOIN plan_versions pv ON pv.id = s.plan_version_id
            JOIN plans p ON p.id = pv.plan_id
            CROSS JOIN LATERAL (
                SELECT CASE WHEN s.interval = 'year'
                    THEN (COALESCE(s.custom_amount, NULLIF(pv.prices -> s.currency ->> 'annual', '')::bigint) * s.quantity + 6) / 12
                    ELSE COALESCE(s.custom_amount, NULLIF(pv.prices -> s.currency ->> 'monthly', '')::bigint) * s.quantity
                END AS monthly
            ) x
            WHERE s.status IN ('active', 'past_due')
            GROUP BY p.code, s.currency
            ORDER BY p.code, s.currency
            SQL);

        return array_values(array_map(fn (stdClass $r): array => ['plan_code' => (string) $r->plan_code, 'currency' => (string) $r->currency, 'mrr' => (int) $r->mrr], $rows));
    }

    /** @return list<array{country: string|null, tenants: int}> */
    private function geography(): array
    {
        $rows = AdminTransaction::connection()->table('tenants')
            ->selectRaw('country, count(*)::int as tenants')
            ->groupBy('country')->orderByDesc('tenants')->orderBy('country')->get();

        return array_values(array_map(fn (stdClass $r): array => ['country' => $r->country === null ? null : (string) $r->country, 'tenants' => (int) $r->tenants], $rows->all()));
    }

    /**
     * Cohort retention per creation month: a tenant counts as retained in month i when it had not reached `cancelled`
     * before that month ended. `retained[0]` is the size of the cohort (month 0).
     *
     * @return list<array{cohort_month: string, size: int, retained: list<int>}>
     */
    private function cohorts(int $months): array
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $db = AdminTransaction::connection();
        $rows = $db->select(<<<'SQL'
            SELECT id, to_char(date_trunc('month', created_at AT TIME ZONE 'UTC'), 'YYYY-MM') AS cohort_month,
                   (created_at AT TIME ZONE 'UTC') AS created_at
            FROM tenants
            WHERE created_at >= ?
            SQL, [$now->modify('-'.($months - 1).' months')->modify('first day of this month')->setTime(0, 0)->format('Y-m-d H:i:sP')]);

        $cancelled = $db->table('tenant_lifecycle_events')
            ->where('to_status', 'cancelled')
            ->selectRaw('tenant_id, min(occurred_at) as cancelled_at')
            ->groupBy('tenant_id')->get()->keyBy('tenant_id');

        /** @var array<string, int> $size */
        $size = [];
        /** @var array<string, list<int>> $retained */
        $retained = [];
        /** @var array<string, DateTimeImmutable> $startMonth */
        $startMonth = [];
        foreach ($rows as $row) {
            $created = new DateTimeImmutable((string) $row->created_at);
            $key = (string) $row->cohort_month;
            if (! isset($size[$key])) {
                $size[$key] = 0;
                $retained[$key] = array_fill(0, $months, 0);
                $startMonth[$key] = $created->modify('first day of this month')->setTime(0, 0);
            }
            $size[$key]++;
            $cancel = $cancelled->get($row->id);
            $cancelledAt = $cancel === null ? null : new DateTimeImmutable((string) ((array) $cancel)['cancelled_at']);
            for ($i = 0; $i < $months; $i++) {
                $end = $startMonth[$key]->modify("+{$i} months")->modify('+1 month');
                if ($cancelledAt !== null && $cancelledAt < $end) {
                    break;
                }
                $retained[$key][$i]++;
            }
        }

        ksort($size);
        $out = [];
        foreach ($size as $key => $count) {
            $out[] = ['cohort_month' => (string) $key, 'size' => $count, 'retained' => array_values($retained[$key])];
        }

        return $out;
    }

    /** @return array{approvals: int, gdpr: int} */
    private function openItems(): array
    {
        $db = AdminTransaction::connection();

        return [
            'approvals' => (int) $db->table('admin_approvals')->where('status', 'pending')->where('expires_at', '>', $this->clock->now())->count(),
            'gdpr' => (int) $db->table('gdpr_requests')->whereIn('status', ['open', 'in_progress'])->count(),
        ];
    }
}
