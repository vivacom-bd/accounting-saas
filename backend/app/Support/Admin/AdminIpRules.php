<?php

declare(strict_types=1);

namespace App\Support\Admin;

use Symfony\Component\HttpFoundation\IpUtils;

/** Admin IP allowlists (plan Task 3): an empty list allows every address; otherwise the address must match one entry. */
final class AdminIpRules
{
    /** @param  list<string>  $allowlist  addresses or CIDR blocks */
    public static function permits(array $allowlist, ?string $ip): bool
    {
        if ($allowlist === []) {
            return true;
        }

        return $ip !== null && IpUtils::checkIp($ip, $allowlist);
    }

    /** @return list<string> */
    public static function global(): array
    {
        $list = config('admin.ip_allowlist', []);

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
