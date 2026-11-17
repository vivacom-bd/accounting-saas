<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Clock;
use App\Notifications\Billing\PaymentReminder;
use App\Notifications\Billing\TrialEnding;
use App\Services\Billing\TenantOwners;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily SaaS dunning (plan Task 12; not the tenants' own invoice dunning, `dunning:run`): payment reminders to the
 * owners of past-due organisations on days 1, 4 and 7 after the first failure, and trial-ending notices 3 and 1 days
 * before the trial ends. Each (tenant, kind, day, anchor) is claimed in `billing_notices_sent` before the mail goes
 * out, so a re-run, or a second server, never sends it twice; a day the scheduler missed is caught up with the latest
 * due notice only. Keyset-paged over the platform tenant directory.
 */
final class RunSaasDunning extends Command
{
    private const array REMINDER_DAYS = [1, 4, 7];

    private const array TRIAL_DAYS = [1, 3];

    protected $description = 'Send payment reminders and trial-ending notices to organisation owners';

    protected $signature = 'saas-dunning:run {--page=500 : Tenants per keyset page}';

    public function handle(Clock $clock, TenantOwners $owners): int
    {
        $page = max(1, (int) $this->option('page'));
        $now = $clock->now();
        $db = AdminTransaction::connection();
        $after = '00000000-0000-0000-0000-000000000000';
        $sent = 0;

        do {
            $rows = $db->table('tenants')
                ->select(['id', 'name', 'lifecycle', 'trial_ends_at', 'delinquent_since'])
                ->whereIn('lifecycle', ['past_due', 'trial'])
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit($page)
                ->get();

            foreach ($rows as $row) {
                $after = (string) $row->id;
                [$kind, $day, $anchor] = $row->lifecycle === 'past_due'
                    ? $this->reminder($row->delinquent_since, $now)
                    : $this->trial($row->trial_ends_at, $now);
                if ($kind === null || $day === null || $anchor === null) {
                    continue;
                }
                // Claim first: the primary key makes a repeat (or a concurrent run) insert nothing.
                $claimed = $db->table('billing_notices_sent')->insertOrIgnore([
                    'tenant_id' => $after, 'kind' => $kind, 'day' => $day, 'anchor' => $anchor, 'sent_at' => $now->format('Y-m-d H:i:sP'),
                ]);
                if ($claimed === 0) {
                    continue;
                }
                try {
                    $owners->notify($after, $kind === 'payment_reminder' ? new PaymentReminder((string) $row->name, $day) : new TrialEnding((string) $row->name, $day));
                    $sent++;
                } catch (Throwable $e) {
                    $db->table('billing_notices_sent')->where(['tenant_id' => $after, 'kind' => $kind, 'day' => $day, 'anchor' => $anchor])->delete();
                    report($e);
                }
            }
        } while ($rows->count() === $page);

        $this->info("Billing notices sent: {$sent}");

        return self::SUCCESS;
    }

    /** @return array{0: string|null, 1: int|null, 2: string|null} kind, offset day, anchor date */
    private function reminder(mixed $delinquentSince, DateTimeImmutable $now): array
    {
        if ($delinquentSince === null) {
            return [null, null, null];
        }
        $since = new DateTimeImmutable((string) $delinquentSince);
        $elapsed = intdiv($now->getTimestamp() - $since->getTimestamp(), 86400);
        $due = array_filter(self::REMINDER_DAYS, fn (int $d): bool => $d <= $elapsed);

        return $due === [] ? [null, null, null] : ['payment_reminder', max($due), $since->format('Y-m-d')];
    }

    /** @return array{0: string|null, 1: int|null, 2: string|null} */
    private function trial(mixed $trialEndsAt, DateTimeImmutable $now): array
    {
        if ($trialEndsAt === null) {
            return [null, null, null];
        }
        $end = new DateTimeImmutable((string) $trialEndsAt);
        $left = $end->getTimestamp() - $now->getTimestamp();
        if ($left < 0) {
            return [null, null, null];
        }
        $daysLeft = intdiv($left, 86400);
        $due = array_filter(self::TRIAL_DAYS, fn (int $d): bool => $d >= $daysLeft);

        return $due === [] ? [null, null, null] : ['trial_ending', min($due), $end->format('Y-m-d')];
    }
}
