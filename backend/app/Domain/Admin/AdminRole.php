<?php

declare(strict_types=1);

namespace App\Domain\Admin;

/** Platform staff roles (10_SaaS_Admin §6). Stored in admin_users.role. */
enum AdminRole: string
{
    case Owner = 'owner';
    case Finance = 'finance';
    case Sales = 'sales';
    case Support = 'support';
    case Engineer = 'engineer';
    case Readonly = 'readonly';
}
