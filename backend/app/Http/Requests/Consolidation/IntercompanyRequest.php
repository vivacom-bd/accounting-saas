<?php

declare(strict_types=1);

namespace App\Http\Requests\Consolidation;

use App\Domain\Consolidation\Intercompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /consolidation/members/{member}/intercompany (D16.7): flags mapped member accounts intercompany, and links the member
 * company's contacts to the members they stand for. `ic_class` null clears an account's flag; `counterparty_member_id`
 * null on a contact removes its link. An account without a counterparty takes it from each line's contact.
 */
final class IntercompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'accounts' => ['present', 'array', 'max:1000'],
            'accounts.*.account_id' => ['required', 'uuid'],
            'accounts.*.ic_class' => ['present', 'nullable', 'string', 'in:'.implode(',', Intercompany::CLASSES)],
            'accounts.*.counterparty_member_id' => ['nullable', 'uuid'],
            'accounts.*.net_investment' => ['sometimes', 'boolean'],
            'contacts' => ['present', 'array', 'max:1000'],
            'contacts.*.contact_id' => ['required', 'uuid'],
            'contacts.*.counterparty_member_id' => ['present', 'nullable', 'uuid'],
        ];
    }

    /** @return list<array{account_id: string, ic_class: string|null, counterparty_member_id: string|null, net_investment: bool}> */
    public function accounts(): array
    {
        $out = [];
        foreach ((array) $this->validated()['accounts'] as $a) {
            $out[] = [
                'account_id' => (string) $a['account_id'], 'ic_class' => isset($a['ic_class']) ? (string) $a['ic_class'] : null,
                'counterparty_member_id' => isset($a['counterparty_member_id']) ? (string) $a['counterparty_member_id'] : null,
                'net_investment' => (bool) ($a['net_investment'] ?? false),
            ];
        }

        return $out;
    }

    /** @return list<array{contact_id: string, counterparty_member_id: string|null}> */
    public function contacts(): array
    {
        $out = [];
        foreach ((array) $this->validated()['contacts'] as $c) {
            $out[] = ['contact_id' => (string) $c['contact_id'], 'counterparty_member_id' => isset($c['counterparty_member_id']) ? (string) $c['counterparty_member_id'] : null];
        }

        return $out;
    }
}
