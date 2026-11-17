<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\Admin\AdminUser;

/** Where an admin request came from, before anyone is signed in (password and TOTP steps). */
final readonly class AdminRequestContext
{
    public function __construct(
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $requestId = null,
    ) {}

    /** The actor once the staff member is known. */
    public function actorFor(AdminUser $admin): AdminActor
    {
        return new AdminActor($admin->id, $admin->role, $this->ip, $this->userAgent, $this->requestId);
    }
}
