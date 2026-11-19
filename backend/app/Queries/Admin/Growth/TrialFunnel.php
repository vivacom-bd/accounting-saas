<?php

declare(strict_types=1);

namespace App\Queries\Admin\Growth;

use App\Contracts\Clock;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;

/**
 * Trials funnel and activation (plan Task 16). `funnel()` counts each stage for organisations created in a range;
 * `trials()` lists the organisations on trial now with their activation flags and days left. Activation comes from
 * sysops.admin_activation_flags (companies, posted journals, members); everything else is the tenant directory.
 */
final class TrialFunnel
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @return array{signups: int, created_company: int, posted_first_journal: int, converted: int, conversion_rate_bp: int}
     */
    public function funnel(?string $from, ?string $to): array
    {
        $db = AdminTransaction::connection();
        $query = $db->table('tenants')->select(['id']);
        if ($from !== null) {
            $query->where('created_at', '>=', $from.' 00:00:00+00');
        }
        if ($to !== null) {
            $query->where('created_at', '<=', $to.' 23:59:59+00');
        }
        /** @var list<string> $ids */
        $ids = $query->pluck('id')->map(fn ($v): string => (string) $v)->all();
        $signups = count($ids);

        if ($signups === 0) {
            return ['signups' => 0, 'created_company' => 0, 'posted_first_journal' => 0, 'converted' => 0, 'conversion_rate_bp' => 0];
        }

        $flags = $this->flags($ids);
        $createdCompany = count(array_filter($flags, fn (array $f): bool => $f['has_company']));
        $posted = count(array_filter($flags, fn (array $f): bool => $f['has_posted']));

        /** @var object{converted: int} $row */
        $row = $db->selectOne(
            "SELECT count(DISTINCT tenant_id)::int AS converted FROM tenant_lifecycle_events WHERE to_status IN ('active','past_due') AND tenant_id = ANY(?::uuid[])",
            [$this->uuidArray($ids)],
        );
        $converted = (int) ($row->converted ?? 0);

        return [
            'signups' => $signups,
            'created_company' => $createdCompany,
            'posted_first_journal' => $posted,
            'converted' => $converted,
            'conversion_rate_bp' => (int) round(($converted / $signups) * 10000),
        ];
    }

    /**
     * @return list<array{id: string, name: string, slug: string, owner_email: string|null, trial_ends_at: string|null, days_left: int|null, has_company: bool, has_posted: bool, members: int, activation_score: int}>
     */
    public function trials(): array
    {
        $db = AdminTransaction::connection();
        $rows = $db->table('tenants')->where('lifecycle', 'trial')->orderBy('trial_ends_at')->orderBy('id')
            ->get(['id', 'name', 'slug', 'trial_ends_at']);

        $ids = $rows->map(fn (object $r): string => (string) $r->id)->all();
        $flags = $this->flags($ids);
        $now = $this->clock->now();

        $owners = $ids === [] ? collect() : $db->table('tenant_users as tu')
            ->join('users as u', 'u.id', '=', 'tu.user_id')
            ->whereIn('tu.tenant_id', $ids)->where('tu.role', 'owner')->where('tu.status', 'active')
            ->orderBy('tu.created_at')->get(['tu.tenant_id', 'u.email'])->keyBy('tenant_id');

        $out = [];
        foreach ($rows as $r) {
            $flag = $flags[(string) $r->id] ?? ['has_company' => false, 'has_posted' => false, 'members' => 0];
            $daysLeft = null;
            if ($r->trial_ends_at !== null) {
                $end = new DateTimeImmutable((string) $r->trial_ends_at);
                $daysLeft = max(0, (int) ceil(($end->getTimestamp() - $now->getTimestamp()) / 86400));
            }
            $done = (int) $flag['has_company'] + (int) $flag['has_posted'] + ($flag['members'] > 1 ? 1 : 0);
            $out[] = [
                'id' => (string) $r->id, 'name' => (string) $r->name, 'slug' => (string) $r->slug,
                'owner_email' => $owners->get((string) $r->id)?->email,
                'trial_ends_at' => $r->trial_ends_at === null ? null : (new DateTimeImmutable((string) $r->trial_ends_at))->format(DATE_RFC3339),
                'days_left' => $daysLeft,
                'has_company' => (bool) $flag['has_company'], 'has_posted' => (bool) $flag['has_posted'], 'members' => (int) $flag['members'],
                'activation_score' => (int) round(($done / 3) * 100),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, array{has_company: bool, has_posted: bool, members: int}>
     */
    private function flags(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = AdminTransaction::connection()->select('SELECT * FROM sysops.admin_activation_flags(?::uuid[])', [$this->uuidArray($ids)]);
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->tenant_id] = ['has_company' => (bool) $row->has_company, 'has_posted' => (bool) $row->has_posted, 'members' => (int) $row->members];
        }

        return $out;
    }

    /** @param  array<int, string>  $ids */
    private function uuidArray(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
    }
}
