<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\Clock;
use App\Notifications\Billing\TrialNudge;
use App\Services\Billing\TenantOwners;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily trial nudges (plan Task 16): day 3 ("add your first company"), day 7 ("post your first journal") and day 12
 * ("choose a plan"), counted from the organisation's creation. Each (tenant, day) is claimed in `billing_notices_sent`
 * before the mail goes out, so a re-run or a second server never sends it twice. The `nudges_enabled` switch defaults
 * on; staff can turn it off in the admin.
 */
final class SendTrialNudges extends Command
{
    private const array DAYS = [
        3 => 'Add your first company to start keeping books.',
        7 => 'Post your first journal or invoice to see Ledgerly working.',
        12 => 'Choose a plan before your trial ends to keep working without interruption.',
    ];

    protected $description = 'Send the day 3, 7 and 12 onboarding nudges to organisations on trial';

    protected $signature = 'trial-nudges:send {--page=500 : Tenants per keyset page}';

    public function handle(Clock $clock, TenantOwners $owners): int
    {
        if (! $this->enabled()) {
            $this->info('Trial nudges are switched off.');

            return self::SUCCESS;
        }

        $page = max(1, (int) $this->option('page'));
        $now = $clock->now();
        $db = AdminTransaction::connection();
        $after = '00000000-0000-0000-0000-000000000000';
        $sent = 0;

        do {
            $rows = $db->table('tenants')
                ->select(['id', 'name', 'created_at'])
                ->where('lifecycle', 'trial')->where('id', '>', $after)
                ->orderBy('id')->limit($page)->get();

            foreach ($rows as $row) {
                $after = (string) $row->id;
                $created = new DateTimeImmutable((string) $row->created_at);
                $day = intdiv($now->getTimestamp() - $created->getTimestamp(), 86400);
                if (! isset(self::DAYS[$day])) {
                    continue;
                }
                $anchor = $created->format('Y-m-d');
                $claimed = $db->table('billing_notices_sent')->insertOrIgnore([
                    'tenant_id' => $after, 'kind' => 'trial_nudge', 'day' => $day, 'anchor' => $anchor, 'sent_at' => $now->format('Y-m-d H:i:sP'),
                ]);
                if ($claimed === 0) {
                    continue;
                }
                try {
                    $owners->notify($after, new TrialNudge((string) $row->name, $day, self::DAYS[$day]));
                    $sent++;
                } catch (Throwable $e) {
                    $db->table('billing_notices_sent')->where(['tenant_id' => $after, 'kind' => 'trial_nudge', 'day' => $day, 'anchor' => $anchor])->delete();
                    report($e);
                }
            }
        } while ($rows->count() === $page);

        $this->info("Trial nudges sent: {$sent}");

        return self::SUCCESS;
    }

    private function enabled(): bool
    {
        $row = AdminTransaction::connection()->table('platform_settings')->where('key', 'nudges_enabled')->first();
        if ($row === null) {
            return true;
        }
        /** @var array{enabled?: bool} $value */
        $value = json_decode((string) $row->value, true, flags: JSON_THROW_ON_ERROR);

        return (bool) ($value['enabled'] ?? true);
    }
}
