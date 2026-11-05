<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Reports\BalanceSheetClassifier;
use App\Http\Support\Json;
use App\Models\Ledger\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Account */
final class AccountResource extends JsonResource
{
    private function balanceSheetTag(): ?string
    {
        foreach (['current', 'non_current'] as $class) {
            if (in_array('bs:'.$class, $this->resource->reporting_tags, true)) {
                return $class;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'account_type' => $this->account_type,
            'account_subtype' => $this->account_subtype,
            'normal_balance' => $this->normal_balance,
            'parent_id' => $this->parent_id,
            'currency_policy' => $this->currency_policy->value,
            'fixed_currency' => $this->fixed_currency,
            'control_type' => $this->control_type->value,
            'allow_manual_journals' => $this->allow_manual_journals,
            'is_postable' => $this->is_postable,
            'is_active' => $this->is_active,
            'is_hidden' => $this->is_hidden,
            /** IFRS Accounting Taxonomy element name (ifrs-full), shown for reference only. */
            'ifrs_element' => $this->ifrs_element,
            'description' => $this->description,
            /** The company's own balance sheet class for an asset or liability (the `bs:*` tag); null when the account follows the rules. */
            'balance_sheet_class' => $this->balanceSheetTag(),
            /** What the rules say without a tag: current, non_current, or null (unknown, shown as current). Assets and liabilities only. */
            'balance_sheet_default' => BalanceSheetClassifier::classify($this->account_type, $this->account_subtype, $this->control_type->value, [], $this->ifrs_element)?->value,
            'version' => $this->lock_version,
            /** @var array<string, string> dimension id → required|optional|forbidden */
            'dimension_rules' => $this->whenLoaded('dimensionRules', fn () => $this->dimensionRules
                ->mapWithKeys(fn ($rule) => [$rule->dimension_id => $rule->rule->value])->all()),
            'can' => Json::can($request, $this->resource, ['update' => 'update', 'deactivate' => 'deactivate']),
        ];
    }
}
