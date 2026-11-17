<?php

declare(strict_types=1);

namespace App\Queries\Admin\Ops;

use App\Support\Admin\AdminTransaction;

/** The stored maintenance switch as the admin sees it (the row itself, not the tenant app's cached view of it). */
final class MaintenanceSetting
{
    /** @return array{enabled: bool, message: string|null, ends_at: string|null} */
    public function __invoke(): array
    {
        $row = AdminTransaction::connection()->table('platform_settings')->where('key', 'maintenance')->first();
        /** @var array{enabled?: bool, message?: string|null, ends_at?: string|null} $value */
        $value = $row === null ? [] : json_decode((string) $row->value, true, flags: JSON_THROW_ON_ERROR);

        return ['enabled' => (bool) ($value['enabled'] ?? false), 'message' => $value['message'] ?? null, 'ends_at' => $value['ends_at'] ?? null];
    }
}
