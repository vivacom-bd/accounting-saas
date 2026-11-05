<?php

declare(strict_types=1);

namespace App\Domain\Consolidation;

/**
 * The group accounts the engine posts to, one per role per group (D16.2). A new group gets them all; a user can rename
 * them but not remove or retype them. Everything else in the group chart is the user's.
 */
final class GroupRoles
{
    public const string RETAINED_EARNINGS = 'retained_earnings';

    public const string TRANSLATION_RESERVE = 'translation_reserve';

    public const string NCI = 'nci';

    public const string NCI_PROFIT_SHARE = 'nci_profit_share';

    public const string GOODWILL = 'goodwill';

    public const string BARGAIN_GAIN = 'bargain_purchase_gain';

    public const string GOODWILL_IMPAIRMENT = 'goodwill_impairment';

    public const string IC_DIFFERENCE = 'ic_difference';

    public const string REMEASUREMENT_GAIN = 'remeasurement_gain';

    public const string DISPOSAL_GAIN = 'disposal_gain';

    public const string RETAINED_INTEREST = 'retained_interest';

    /** @var list<array{code: string, name: string, type: string, subtype: string, role: string}> */
    public const array DEFAULTS = [
        ['code' => 'G3000', 'name' => 'Retained earnings', 'type' => 'equity', 'subtype' => 'Retained Earnings', 'role' => self::RETAINED_EARNINGS],
        ['code' => 'G3200', 'name' => 'Translation reserve', 'type' => 'equity', 'subtype' => 'Reserves', 'role' => self::TRANSLATION_RESERVE],
        ['code' => 'G3500', 'name' => 'Non-controlling interests', 'type' => 'equity', 'subtype' => 'Non-controlling Interests', 'role' => self::NCI],
        ['code' => 'G3510', 'name' => 'Profit attributable to non-controlling interests', 'type' => 'equity', 'subtype' => 'Non-controlling Interests', 'role' => self::NCI_PROFIT_SHARE],
        ['code' => 'G1700', 'name' => 'Goodwill', 'type' => 'asset', 'subtype' => 'Intangible Assets', 'role' => self::GOODWILL],
        ['code' => 'G1850', 'name' => 'Retained interest in former subsidiary', 'type' => 'asset', 'subtype' => 'Investments', 'role' => self::RETAINED_INTEREST],
        ['code' => 'G4900', 'name' => 'Gain on bargain purchase', 'type' => 'income', 'subtype' => 'Other Income', 'role' => self::BARGAIN_GAIN],
        ['code' => 'G4910', 'name' => 'Remeasurement of previously held interest', 'type' => 'income', 'subtype' => 'Other Income', 'role' => self::REMEASUREMENT_GAIN],
        ['code' => 'G4920', 'name' => 'Gain or loss on loss of control', 'type' => 'income', 'subtype' => 'Other Income', 'role' => self::DISPOSAL_GAIN],
        ['code' => 'G6900', 'name' => 'Impairment of goodwill', 'type' => 'expense', 'subtype' => 'Other Expense', 'role' => self::GOODWILL_IMPAIRMENT],
        ['code' => 'G6910', 'name' => 'Intercompany differences', 'type' => 'expense', 'subtype' => 'Other Expense', 'role' => self::IC_DIFFERENCE],
    ];

    public static function isProfitAndLoss(string $type): bool
    {
        return $type === 'income' || $type === 'expense';
    }
}
