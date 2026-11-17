<?php

declare(strict_types=1);

namespace App\Actions\Admin\Auth;

/** Recovery codes are random 21-character strings; only their SHA-256 is stored, and each matches once. */
final class RecoveryCodes
{
    public static function hash(string $code): string
    {
        return hash('sha256', trim($code));
    }

    /**
     * The stored hashes without the one matching $code, or null when none matches.
     *
     * @param  list<string>  $hashes
     * @return list<string>|null
     */
    public static function consume(array $hashes, string $code): ?array
    {
        $candidate = self::hash($code);
        foreach ($hashes as $i => $hash) {
            if (hash_equals($hash, $candidate)) {
                unset($hashes[$i]);

                return array_values($hashes);
            }
        }

        return null;
    }
}
