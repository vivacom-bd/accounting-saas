<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Models\Admin\AdminUser;
use Illuminate\Support\Collection;

/** Every staff member, newest invitation last. The team is small, so the list is not paged. */
final class ListStaff
{
    /** @return Collection<int, AdminUser> */
    public function __invoke(): Collection
    {
        return AdminUser::query()->orderBy('name')->orderBy('id')->get();
    }
}
