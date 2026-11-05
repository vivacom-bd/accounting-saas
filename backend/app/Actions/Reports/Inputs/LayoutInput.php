<?php

declare(strict_types=1);

namespace App\Actions\Reports\Inputs;

final readonly class LayoutInput
{
    /** @param array<string, mixed> $params as sent: cleaned and checked by the Action */
    public function __construct(
        public string $companyId,
        public string $report,
        public string $name,
        public string $visibility,
        public array $params,
        public bool $isDefault,
        public ?string $layoutId = null,
        public ?int $version = null,
    ) {}
}
