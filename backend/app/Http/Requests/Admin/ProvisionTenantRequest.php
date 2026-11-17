<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Body of `POST /api/admin/tenants`. The ability is checked by the route's `admin.can:tenants.provision`. */
final class ProvisionTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'organisation_name' => ['required', 'string', 'max:120'],
            'country' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'owner_email' => ['required', 'email:rfc', 'max:254'],
            'owner_name' => ['required', 'string', 'max:120'],
            'plan_code' => ['required', 'string', 'max:64'],
            'billing' => ['required', 'string', 'in:trial,offline'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'currency' => ['nullable', 'string', 'in:USD,KES,EUR'],
            'interval' => ['nullable', 'string', 'in:month,year'],
            'custom_amount' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
