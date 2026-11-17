<?php

declare(strict_types=1);

use App\Domain\Fx\MonetaryAccounts;
use App\Domain\Ledger\ControlType;
use App\Domain\Ledger\CurrencyPolicy;

// IAS 21.16: only items settled in a fixed number of currency units are revalued.
it('classifies accounts for revaluation', function (string $type, ControlType $control, CurrencyPolicy $policy, ?string $subtype, bool $revalued): void {
    expect(MonetaryAccounts::isRevalued($type, $control, $policy, $subtype))->toBe($revalued);
})->with([
    'receivables' => ['asset', ControlType::Ar, CurrencyPolicy::Any, 'Current Assets', true],
    'payables' => ['liability', ControlType::Ap, CurrencyPolicy::Any, 'Current Liabilities', true],
    'foreign bank' => ['asset', ControlType::Bank, CurrencyPolicy::Fixed, 'Bank Account', true],
    'other receivable' => ['asset', ControlType::None, CurrencyPolicy::Any, 'Current Assets', true],
    'prepayments' => ['asset', ControlType::None, CurrencyPolicy::Any, 'Prepayments', false],
    'deferred income' => ['liability', ControlType::None, CurrencyPolicy::Any, 'Deferred Income', false],
    'inventory' => ['asset', ControlType::None, CurrencyPolicy::Any, 'Inventory', false],
    'fixed assets' => ['asset', ControlType::FixedAsset, CurrencyPolicy::Any, 'Property, Plant & Equipment', false],
    'base only' => ['asset', ControlType::None, CurrencyPolicy::BaseOnly, 'Current Assets', false],
    'income' => ['income', ControlType::None, CurrencyPolicy::Any, 'Operating Revenue', false],
]);

// Plan 3c.29c: an intragroup loan is monetary even when it sits in a non-current subtype (IAS 21.16, .23), so the company can
// say so with the `fx:monetary` tag; a loan receivable element is monetary by default. Nothing makes PPE, intangibles,
// inventory, equity or profit and loss monetary, whatever the tag says.
it('lets an explicit tag or a loan element make a non-current item monetary, and never a non-monetary one', function (string $type, ControlType $control, ?string $subtype, array $tags, ?string $element, bool $revalued): void {
    expect(MonetaryAccounts::isRevalued($type, $control, CurrencyPolicy::Any, $subtype, $tags, $element))->toBe($revalued);
})->with([
    'non-current asset, untagged' => ['asset', ControlType::None, 'Non-current Assets', [], null, false],
    'non-current asset tagged monetary' => ['asset', ControlType::None, 'Non-current Assets', ['bs:non_current', 'fx:monetary'], null, true],
    'loan receivable element' => ['asset', ControlType::None, 'Non-current Assets', [], 'NoncurrentLoansAndReceivables', true],
    'investment tagged monetary (a bond, an intragroup loan)' => ['asset', ControlType::None, 'Investments', ['fx:monetary'], null, true],
    'non-current liability (a loan payable)' => ['liability', ControlType::None, 'Non-current Liabilities', [], null, true],
    'loan payable tagged non-monetary' => ['liability', ControlType::None, 'Non-current Liabilities', ['fx:non_monetary'], null, false],
    'loan element tagged non-monetary' => ['asset', ControlType::None, 'Non-current Assets', ['fx:non_monetary'], 'NoncurrentLoansAndReceivables', false],
    'PPE tagged monetary' => ['asset', ControlType::None, 'Property, Plant & Equipment', ['fx:monetary'], null, false],
    'intangible tagged monetary' => ['asset', ControlType::None, 'Intangible Assets', ['fx:monetary'], null, false],
    'inventory tagged monetary' => ['asset', ControlType::None, 'Inventory', ['fx:monetary'], null, false],
    'fixed asset control tagged monetary' => ['asset', ControlType::FixedAsset, 'Non-current Assets', ['fx:monetary'], null, false],
    'accumulated depreciation tagged monetary' => ['asset', ControlType::AccumDepr, 'Non-current Assets', ['fx:monetary'], null, false],
    'equity tagged monetary' => ['equity', ControlType::None, 'Reserves', ['fx:monetary'], null, false],
    'expense tagged monetary' => ['expense', ControlType::None, 'Operating Expense', ['fx:monetary'], null, false],
    'receivable control tagged non-monetary stays monetary' => ['asset', ControlType::Ar, 'Current Assets', ['fx:non_monetary'], null, true],
]);

it('never revalues a base-only account, tagged or not', function (): void {
    expect(MonetaryAccounts::isRevalued('asset', ControlType::None, CurrencyPolicy::BaseOnly, 'Non-current Assets', ['fx:monetary'], null))->toBeFalse();
});
