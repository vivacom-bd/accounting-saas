<?php

declare(strict_types=1);

namespace App\Domain\Fx;

use App\Domain\Ledger\ControlType;
use App\Domain\Ledger\CurrencyPolicy;

/**
 * Which balance-sheet accounts IAS 21.23 revalues at the closing rate: monetary items only (cash, bank,
 * receivables, payables, tax balances, loans). Non-monetary items (PPE and depreciation, inventory, intangibles,
 * investments, prepayments, deferred income, equity, income and expense) stay at their historical rate. An account takes part only when its
 * currency policy lets it hold foreign currency at all; turning that off (base_only) is how a company
 * opts any other account out.
 *
 * Being non-current does not make an item non-monetary (IAS 21.16): a long-term loan, an intragroup loan included,
 * is monetary (plan 3c.29c). The company's own answer is the tag `fx:monetary` or `fx:non_monetary` on
 * `reporting_tags`, which wins over the subtype default; a loan receivable element is monetary by default. The tag can
 * never make PPE, intangibles, inventory, depreciation, equity or profit and loss monetary, and a control account
 * (receivables, payables, bank, cash, tax) is always monetary.
 */
final class MonetaryAccounts
{
    public const string TAG_MONETARY = 'fx:monetary';

    public const string TAG_NON_MONETARY = 'fx:non_monetary';

    /** Subtypes of the chart templates that hold non-monetary items (IAS 21.16). */
    private const array NON_MONETARY_SUBTYPES = [
        'Inventory', 'Property, Plant & Equipment', 'Intangible Assets', 'Non-current Assets', 'Investments',
        // IAS 21.16: prepayments for goods and services and deferred income settle in kind, not in cash.
        'Prepayments', 'Deferred Income',
    ];

    /** Subtypes that are never monetary, whatever the company tags. */
    private const array ALWAYS_NON_MONETARY_SUBTYPES = ['Inventory', 'Property, Plant & Equipment', 'Property and equipment', 'Intangible Assets'];

    /** IFRS Taxonomy elements of a loan or receivable: a right to a fixed number of currency units. */
    private const array MONETARY_ELEMENTS = ['NoncurrentLoansAndReceivables'];

    /** @param list<string> $tags */
    public static function isRevalued(string $accountType, ControlType $control, CurrencyPolicy $policy, ?string $subtype, array $tags = [], ?string $element = null): bool
    {
        return $policy !== CurrencyPolicy::BaseOnly && self::isMonetary($accountType, $control, $subtype, $tags, $element);
    }

    /**
     * Whether the account holds a monetary item, whatever currency it may hold.
     *
     * @param  list<string>  $tags
     */
    public static function isMonetary(string $accountType, ControlType $control, ?string $subtype, array $tags = [], ?string $element = null): bool
    {
        if (! in_array($accountType, ['asset', 'liability'], true) || ! self::mayBeMonetary($control, $subtype)) {
            return false;
        }
        if ($control !== ControlType::None) {
            return true;
        }
        if (in_array(self::TAG_NON_MONETARY, $tags, true)) {
            return false;
        }
        if (in_array(self::TAG_MONETARY, $tags, true) || in_array($element, self::MONETARY_ELEMENTS, true)) {
            return true;
        }

        return ! in_array($subtype, self::NON_MONETARY_SUBTYPES, true);
    }

    /** Whether a company may tag the account monetary at all (never PPE, intangibles, inventory or their controls). */
    public static function mayBeMonetary(ControlType $control, ?string $subtype): bool
    {
        return ! in_array($control, [ControlType::FixedAsset, ControlType::AccumDepr, ControlType::RetainedEarnings], true)
            && ! in_array($subtype, self::ALWAYS_NON_MONETARY_SUBTYPES, true);
    }
}
