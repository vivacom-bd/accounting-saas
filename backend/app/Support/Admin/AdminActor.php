<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Domain\Admin\AdminRole;

/** Who is acting in the platform admin surface; passed explicitly to every admin Action. */
final readonly class AdminActor
{
    public function __construct(
        public string $id,
        public AdminRole $role,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $requestId = null,
    ) {}
}
