<?php

declare(strict_types=1);

namespace App\Support\Impersonation;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Read-only impersonation enforced by the database (T6, 13 §18): `SET SESSION default_transaction_read_only = on` on
 * the request's tenant connections, so a write anywhere (including a GET that happens to write) fails with SQLSTATE
 * 25006 and nothing changes. `pgsql_audit` is never included: the impersonation's own audit events are written there.
 *
 * Connections outlive a request (pooled workers, the test application), so disable() must always run: the middleware
 * calls it in `finally` and again in terminate(). A connection that cannot be reset cleanly (an open transaction, a
 * broken link) is disconnected instead, and the next request opens a fresh one, which is read-write by default.
 * A connection opened or re-opened while enabled gets the setting from reapply() (ConnectionEstablished listener).
 */
final class ReadOnlyConnections
{
    private bool $enabled = false;

    /** @param list<string> $connections */
    public function __construct(private readonly DatabaseManager $db, private readonly array $connections) {}

    /** Turns read-only on for the default tenant connection now and for every listed one that is or becomes open. */
    public function enable(): void
    {
        $this->enabled = true;
        $this->db->connection($this->connections[0] ?? null);
        foreach ($this->open() as $connection) {
            $connection->statement('SET SESSION default_transaction_read_only = on');
        }
    }

    public function disable(): void
    {
        if (! $this->enabled) {
            return;
        }
        $this->enabled = false;
        foreach ($this->open() as $name => $connection) {
            try {
                if ($connection->transactionLevel() > 0) {
                    $this->db->purge($name);

                    continue;
                }
                $connection->statement('SET SESSION default_transaction_read_only = off');
            } catch (Throwable) {
                $this->db->purge($name);
            }
        }
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function reapply(Connection $connection): void
    {
        if ($this->enabled && in_array($connection->getName(), $this->connections, true)) {
            $connection->statement('SET SESSION default_transaction_read_only = on');
        }
    }

    /** @return array<string, Connection> */
    private function open(): array
    {
        return array_filter(
            $this->db->getConnections(),
            fn (Connection $c, string $name): bool => in_array($name, $this->connections, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
