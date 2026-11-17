<?php

declare(strict_types=1);

namespace App\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * PostgreSQL inet[] column ⇄ list<string> of addresses or CIDR blocks (`10.0.0.0/8`, `2001:db8::/32`, `192.0.2.7`).
 * Anything that is not an address or block is refused.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
final class PgInetArray implements CastsAttributes
{
    /** @return list<string> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! is_string($value)) {
            return [];
        }
        $inner = trim($value, '{}');

        return $inner === '' ? [] : array_map(fn (string $v): string => trim($v, '"'), explode(',', $inner));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $items = is_array($value) ? $value : [];
        foreach ($items as $item) {
            if (! self::isAddressOrBlock($item)) {
                throw new InvalidArgumentException('Unsupported inet[] element.');
            }
        }

        return '{'.implode(',', $items).'}';
    }

    public static function isAddressOrBlock(string $item): bool
    {
        $parts = explode('/', $item, 2);
        $ip = filter_var($parts[0], FILTER_VALIDATE_IP);
        if ($ip === false) {
            return false;
        }
        if (! isset($parts[1])) {
            return true;
        }
        $max = str_contains($parts[0], ':') ? 128 : 32;

        return preg_match('/^\d{1,3}$/', $parts[1]) === 1 && (int) $parts[1] <= $max;
    }
}
