<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\Admin\ApprovalKind;
use App\Models\Admin\AdminApproval;
use App\Support\Admin\AdminActor;

/** Carries out an approved request of one kind; registered with the container tag `admin.approval_executors`. */
interface ApprovalExecutor
{
    public function kind(): ApprovalKind;

    /** Runs inside the approval's admin transaction; throwing leaves the request pending. */
    public function execute(AdminActor $approver, AdminApproval $approval): void;
}
