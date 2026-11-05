<?php

declare(strict_types=1);

namespace App\Http\Requests\Tax;

use App\Http\Requests\CompanyRequest;
use DateTimeImmutable;

/**
 * One request class for the tax period writes: generate (`authority`, `from`, `months`), save manual amounts (`version`, `amounts`),
 * file (`version`, `reference`) and reopen (`version`, `reason`). The route decides which rules apply.
 */
final class TaxPeriodRequest extends CompanyRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'store' => ['authority' => ['nullable', 'string', 'max:60'], 'from' => ['required', 'date_format:Y-m-d'], 'months' => ['required', 'integer', 'min:1', 'max:24']],
            'amounts' => ['version' => ['required', 'integer', 'min:0'], 'amounts' => ['required', 'array', 'min:1', 'max:30'], 'amounts.*' => ['required', 'integer', 'between:-999999999999999,999999999999999']],
            'file' => ['version' => ['required', 'integer', 'min:0'], 'reference' => ['required', 'string', 'max:120']],
            default => ['version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'min:5', 'max:500']],
        };
    }

    public function version(): int
    {
        return (int) $this->validated()['version'];
    }

    public function authority(): ?string
    {
        $a = $this->validated()['authority'] ?? null;

        return $a === null || trim((string) $a) === '' ? null : trim((string) $a);
    }

    public function from(): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $this->validated()['from']);
    }

    public function months(): int
    {
        return (int) $this->validated()['months'];
    }

    /** @return array<string, int> */
    public function amounts(): array
    {
        /** @var array<string, int> $a */
        $a = array_map('intval', (array) $this->validated()['amounts']);

        return $a;
    }

    public function reference(): string
    {
        return (string) $this->validated()['reference'];
    }

    public function reason(): string
    {
        return (string) $this->validated()['reason'];
    }
}
