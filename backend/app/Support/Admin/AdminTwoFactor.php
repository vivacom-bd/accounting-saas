<?php

declare(strict_types=1);

namespace App\Support\Admin;

/** Whether staff sign-in needs the second step: always in production, otherwise per `admin.two_factor`. */
final class AdminTwoFactor
{
    public static function required(): bool
    {
        return app()->environment('production') || (bool) config('admin.two_factor', true);
    }
}
