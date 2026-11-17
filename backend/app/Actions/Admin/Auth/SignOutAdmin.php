<?php

declare(strict_types=1);

namespace App\Actions\Admin\Auth;

use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;

/** Records a staff sign-out; the caller ends the guard login and the session. */
final class SignOutAdmin
{
    public function __construct(private readonly AdminAuditWriter $audit) {}

    public function __invoke(AdminActor $actor): void
    {
        AdminTransaction::run(fn () => $this->audit->record($actor, 'auth.logout', null, 'admin_user', $actor->id));
    }
}
