<?php

declare(strict_types=1);

namespace App\Support\Features;

use Closure;
use Illuminate\Database\Query\Builder;
use Laravel\Pennant\Drivers\DatabaseDriver;

/**
 * Pennant's database driver, split for two database roles (plan Task 17).
 *
 * - Reads run on the store's `connection` (the tenant app role, SELECT only). A read never stores the resolved
 *   default, which the stock driver does: a stored default would shadow later global changes, and the app role
 *   cannot write the table. Lookup order: the scope's own row, then the global row, then the feature's default.
 * - Writes (admin edits) run on `write_connection` (ledgerly_platform), inside the admin transaction when one is open.
 */
final class LedgerlyFeatureDriver extends DatabaseDriver
{
    /** The scope under which an admin's global switch is stored. */
    public const string GLOBAL_SCOPE = '__global__';

    private bool $writing = false;

    public function get($feature, $scope): mixed
    {
        $record = $this->retrieve($feature, $scope) ?? $this->retrieve($feature, self::GLOBAL_SCOPE);
        if ($record !== null) {
            return json_decode((string) ((array) $record)['value'], flags: JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR);
        }

        $value = $this->resolveValue($feature, $scope);

        return $value === $this->unknownFeatureValue ? false : $value;
    }

    /** @param  array<string, array<int, mixed>>  $features */
    public function getAll($features): array
    {
        $results = [];
        foreach ($features as $feature => $scopes) {
            foreach ($scopes as $i => $scope) {
                $results[$feature][$i] = $this->get($feature, $scope);
            }
        }

        return $results;
    }

    public function set($feature, $scope, $value): void
    {
        $this->asWriter(fn () => parent::set($feature, $scope, $value));
    }

    public function setAll(array $features): void
    {
        $this->asWriter(fn () => parent::setAll($features));
    }

    public function setForAllScopes($feature, $value): void
    {
        $this->asWriter(fn () => parent::setForAllScopes($feature, $value));
    }

    public function delete($feature, $scope): void
    {
        $this->asWriter(fn () => parent::delete($feature, $scope));
    }

    /** @param  array<int, string>|null  $features */
    public function purge($features): void
    {
        $this->asWriter(fn () => parent::purge($features));
    }

    protected function newQuery(): Builder
    {
        $store = "pennant.stores.{$this->name}";
        $connection = $this->writing
            ? ($this->config->get("{$store}.write_connection") ?? $this->config->get("{$store}.connection"))
            : $this->config->get("{$store}.connection");

        return $this->db->connection($connection)->table($this->config->get("{$store}.table") ?? 'features');
    }

    private function asWriter(Closure $write): void
    {
        $this->writing = true;

        try {
            $write();
        } finally {
            $this->writing = false;
        }
    }
}
