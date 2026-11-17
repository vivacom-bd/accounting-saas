<?php

declare(strict_types=1);

namespace App\Queries\Admin\Ops;

use App\Contracts\Clock;
use App\Support\Admin\AdminTransaction;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The admin health page (plan Task 17, 10 §3.9). Every probe runs on its own: a probe that throws reports `down` for
 * its panel and the rest still answer.
 */
final class Health
{
    public const string HEARTBEAT_KEY = 'scheduler:heartbeat';

    public const string SEAL_KEY = 'audit:seal:last_run';

    /** A heartbeat older than this means the scheduler stopped (it beats every minute). */
    private const int HEARTBEAT_STALE_SECONDS = 180;

    /** The seal runs nightly; older than this means a night was missed. */
    private const int SEAL_STALE_SECONDS = 36 * 3600;

    public function __construct(private readonly Clock $clock) {}

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'database' => $this->database(),
            'redis' => $this->redis(),
            'queue' => $this->queue(),
            'outbox_backlog' => $this->outbox(),
            'scheduler_last_beat' => $this->beat(self::HEARTBEAT_KEY, self::HEARTBEAT_STALE_SECONDS, 'down'),
            'audit_seal_last_run' => $this->beat(self::SEAL_KEY, self::SEAL_STALE_SECONDS, 'unknown'),
            // Task 15's mail_log is not merged yet: unknown, not zero.
            'mail_failures_24h' => null,
        ];
    }

    /** "ok" only when the tenant app role and the platform role both answer. */
    private function database(): string
    {
        return $this->probe(function (): string {
            DB::connection('pgsql')->selectOne('SELECT 1');
            AdminTransaction::connection()->selectOne('SELECT 1');

            return 'ok';
        }) ?? 'down';
    }

    private function redis(): string
    {
        return $this->probe(function (): string {
            Redis::connection()->ping();

            return 'ok';
        }) ?? 'down';
    }

    /** @return array{status: string, pending: int|null, failed: int|null, queues: array<string, int>} */
    private function queue(): array
    {
        $queues = [];
        $pending = $this->probe(function () use (&$queues): int {
            foreach ((array) config('admin.ops.queues', []) as $name) {
                $queues[(string) $name] = (int) Queue::size((string) $name);
            }

            return array_sum($queues);
        });
        $failed = $this->probe(fn (): int => AdminTransaction::connection()->table('failed_jobs')->count());

        return ['status' => $pending === null || $failed === null ? 'down' : 'ok', 'pending' => $pending, 'failed' => $failed, 'queues' => $queues];
    }

    /** @return array{status: string, pending: int|null, oldest_at: string|null} */
    private function outbox(): array
    {
        $row = $this->probe(fn (): ?object => AdminTransaction::connection()->selectOne('SELECT pending, oldest FROM sysops.admin_outbox_backlog()'));
        if ($row === null) {
            return ['status' => 'down', 'pending' => null, 'oldest_at' => null];
        }

        $values = (array) $row;

        return [
            'status' => 'ok', 'pending' => (int) $values['pending'],
            'oldest_at' => $values['oldest'] === null ? null : (new DateTimeImmutable((string) $values['oldest']))->format(\DATE_ATOM),
        ];
    }

    /** @return array{status: string, at: string|null} */
    private function beat(string $key, int $staleAfter, string $whenMissing): array
    {
        $at = null;
        $ok = $this->probe(function () use ($key, &$at): bool {
            $value = Cache::get($key);
            $at = is_string($value) ? $value : null;

            return true;
        });
        if ($ok === null) {
            return ['status' => 'down', 'at' => null];
        }
        if ($at === null) {
            return ['status' => $whenMissing, 'at' => null];
        }

        $age = $this->clock->now()->getTimestamp() - (new DateTimeImmutable($at))->getTimestamp();

        return ['status' => $age > $staleAfter ? 'stale' : 'ok', 'at' => $at];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $probe
     * @return T|null null when the probe threw
     */
    private function probe(Closure $probe): mixed
    {
        try {
            return $probe();
        } catch (Throwable) {
            return null;
        }
    }
}
