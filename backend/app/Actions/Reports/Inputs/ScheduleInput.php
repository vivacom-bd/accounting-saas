<?php

declare(strict_types=1);

namespace App\Actions\Reports\Inputs;

final readonly class ScheduleInput
{
    /**
     * @param  list<string>  $userIds  company members who receive it
     * @param  list<string>  $emails  other addresses
     */
    public function __construct(
        public string $companyId,
        public string $layoutId,
        public string $frequency,
        public ?int $weekday,
        public ?int $monthDay,
        public bool $lastDay,
        public int $hour,
        public string $format,
        public array $userIds,
        public array $emails,
        public bool $active,
        public ?int $version = null,
    ) {}
}
