<?php

declare(strict_types=1);

/**
 * Kenya country pack (plan 3c.22, 13 §23 D15 item 10). DATA only: the core never branches on a country, it reads a pack like this
 * one. Facts are from the VAT Act and the Income Tax Act as served by KRA, including Act 19 of 2026, checked 2026-11-05; the
 * withholding income tax rates come from a PwC summary (a secondary source) and say so. Rates change by Finance Act: a company edits
 * them by adding a new rate from a date, never by editing history.
 *
 * Change a fact here and raise `version`; applying the new version to a company adds what is new and never edits what is there.
 *
 * Return sections are labelled in plain words. KRA's iTax form has box numbers; they are NOT mapped here and must not be guessed:
 * they wait until checked against the form.
 */
$from = '2020-01-01';
$checked = '2026-11-05';

// One tax code with a single open-ended rate.
$code = fn (string $code, string $name, string $kind, bool $recoverable, string $rate, string $source, ?string $note = null): array => [
    'code' => $code, 'name' => $name, 'kind' => $kind, 'recoverable' => $recoverable,
    'rates' => [['rate' => $rate, 'from' => $from, 'source' => $source, 'as_of' => $checked]],
    'note' => $note,
];

$vat = 'VAT Act as served by KRA, incl. Act 19 of 2026';
$wht = 'PwC summary (secondary source, verify against ITA Fourth Schedule)';

return [
    'key' => 'ke',
    'version' => 1,
    'name' => 'Kenya',
    'country' => 'KE',
    'authority' => 'KRA',
    'checked_on' => $checked,
    'sources' => [
        ['id' => 'vat_act', 'label' => $vat, 'secondary' => false],
        ['id' => 'ita', 'label' => 'Income Tax Act, Second Schedule, as served by KRA, incl. Act 19 of 2026', 'secondary' => false],
        ['id' => 'pwc', 'label' => $wht, 'secondary' => true],
    ],
    'metadata_notes' => [
        'Rate start dates are set to 1 January 2020 so documents from then on find a rate; history before it is not modelled.',
        'Withholding income tax rates are from a secondary source: secondary source, verify against ITA Fourth Schedule.',
    ],
    // Return and payment are due this many days after the period ends (VAT Act, as served by KRA).
    'filing' => ['frequency' => 'monthly', 'due_days_after_period_end' => 20],
    // s.10 VAT Act: a fully creditable importer of services declares zero; a partly creditable one only the non-creditable part.
    'reverse_charge_mode' => 'net_non_creditable',

    'tax_codes' => [
        $code('KE-VAT16', 'VAT 16% standard rate', 'vat', true, '0.160000', 'vat_act'),
        $code('KE-VAT16-NC', 'VAT 16% not creditable', 'vat', false, '0.160000', 'vat_act', 'Input tax that cannot be claimed (for example on exempt supplies); it becomes part of the cost.'),
        $code('KE-VAT16-RC', 'VAT 16% reverse charge on imported services', 'reverse_charge', true, '0.160000', 'vat_act', 'For a bill from a supplier abroad (VAT Act s.10). The tax is self-assessed: output and input on the same base. Use when the tax is fully creditable.'),
        $code('KE-VAT16-RC-NC', 'VAT 16% reverse charge, not creditable', 'reverse_charge', false, '0.160000', 'vat_act', 'As above when the input tax cannot be claimed: only this non-creditable part is declared, and the input tax becomes cost.'),
        $code('KE-VAT16-IMP', 'VAT 16% on imports', 'vat', true, '0.160000', 'vat_act', 'VAT paid at customs on imported goods.'),
        $code('KE-VAT0', 'VAT 0% zero-rated', 'zero', true, '0.000000', 'vat_act'),
        $code('KE-VAT-EX', 'VAT exempt', 'exempt', false, '0.000000', 'vat_act', 'Exempt supplies: no VAT charged and no input deduction.'),
        // Withholding income tax, resident / non-resident (secondary source).
        $code('KE-WHT-MGMT-R', 'WHT management or professional fees, resident 5%', 'wht', false, '0.050000', 'pwc'),
        $code('KE-WHT-MGMT-NR', 'WHT management or professional fees, non-resident 20%', 'wht', false, '0.200000', 'pwc'),
        $code('KE-WHT-CONTRACT-R', 'WHT contractual fees, resident 3%', 'wht', false, '0.030000', 'pwc'),
        $code('KE-WHT-CONTRACT-NR', 'WHT contractual fees, non-resident 20%', 'wht', false, '0.200000', 'pwc'),
        $code('KE-WHT-ROYALTY-R', 'WHT royalties, resident 5%', 'wht', false, '0.050000', 'pwc'),
        $code('KE-WHT-ROYALTY-NR', 'WHT royalties, non-resident 20%', 'wht', false, '0.200000', 'pwc'),
        $code('KE-WHT-DIVIDEND-R', 'WHT dividends, resident 5%', 'wht', false, '0.050000', 'pwc'),
        $code('KE-WHT-DIVIDEND-NR', 'WHT dividends, non-resident 15%', 'wht', false, '0.150000', 'pwc'),
        $code('KE-WHT-INTEREST-R', 'WHT interest, resident 15%', 'wht', false, '0.150000', 'pwc'),
        $code('KE-WHT-INTEREST-NR', 'WHT interest, non-resident 15%', 'wht', false, '0.150000', 'pwc'),
        $code('KE-WHT-RENT-NR', 'WHT rent, non-resident 30%', 'wht', false, '0.300000', 'pwc'),
        $code('KE-WHT-GOODS-R', 'WHT goods to public entities, resident 0.5%', 'wht', false, '0.005000', 'pwc'),
        $code('KE-WHT-GOODS-NR', 'WHT goods to public entities, non-resident 5%', 'wht', false, '0.050000', 'pwc'),
        $code('KE-WHT-DIGITAL-R', 'WHT digital platform, resident 5%', 'wht', false, '0.050000', 'pwc'),
        $code('KE-WHT-DIGITAL-NR', 'WHT digital platform, non-resident 20%', 'wht', false, '0.200000', 'pwc'),
    ],

    // effect: add or subtract the section's amount in the net; none = shown only. source: codes | reverse_charge_output |
    // reverse_charge_input | manual (entered on the tax period). `map` lists [side, pack tax code].
    'return_sections' => [
        ['key' => 'output_standard', 'group' => 'output', 'label' => 'Sales at the standard rate (16%)', 'effect' => 'add', 'source' => 'codes', 'map' => [['sales', 'KE-VAT16'], ['sales', 'KE-VAT16-NC']]],
        ['key' => 'output_zero', 'group' => 'output', 'label' => 'Zero-rated sales', 'effect' => 'none', 'source' => 'codes', 'map' => [['sales', 'KE-VAT0']]],
        ['key' => 'output_exempt', 'group' => 'output', 'label' => 'Exempt sales', 'effect' => 'none', 'source' => 'codes', 'map' => [['sales', 'KE-VAT-EX']]],
        ['key' => 'output_deemed_imported_services', 'group' => 'output', 'label' => 'Deemed output tax on imported services', 'effect' => 'add', 'source' => 'reverse_charge_output', 'map' => [['reverse_charge', 'KE-VAT16-RC'], ['reverse_charge', 'KE-VAT16-RC-NC']],
            'note' => 'Reverse charge, s.10: a fully creditable importer declares zero here; a partly creditable one declares only the non-creditable part.'],
        ['key' => 'input_local', 'group' => 'input', 'label' => 'Input tax on local purchases', 'effect' => 'subtract', 'source' => 'codes', 'map' => [['purchases', 'KE-VAT16']]],
        ['key' => 'input_imports', 'group' => 'input', 'label' => 'Input tax on imports', 'effect' => 'subtract', 'source' => 'codes', 'map' => [['purchases', 'KE-VAT16-IMP']]],
        ['key' => 'input_imported_services', 'group' => 'input', 'label' => 'Input tax on imported services', 'effect' => 'subtract', 'source' => 'reverse_charge_input', 'map' => [['reverse_charge', 'KE-VAT16-RC'], ['reverse_charge', 'KE-VAT16-RC-NC']]],
        ['key' => 'adjustments', 'group' => 'adjustments', 'label' => 'Adjustments', 'effect' => 'add', 'source' => 'manual', 'map' => [],
            'note' => 'Entered by hand on the tax period. A positive figure adds to the tax payable, a negative one reduces it.'],
        ['key' => 'withholding_vat_credit', 'group' => 'credits', 'label' => 'Withholding VAT credit', 'effect' => 'subtract', 'source' => 'manual', 'map' => [],
            'note' => 'VAT withheld from our receipts by an appointed agent is a credit against VAT payable (s.17(5)). Receipts cannot record it yet, so enter the amount from your withholding certificates.'],
        ['key' => 'credit_brought_forward', 'group' => 'credits', 'label' => 'Credit brought forward', 'effect' => 'subtract', 'source' => 'manual', 'map' => [],
            'note' => 'Entered by hand on the tax period, from the previous return.'],
    ],

    // Tax depreciation classes, Income Tax Act Second Schedule. Rates in basis points of cost, straight line per item. The tax
    // depreciation schedule (3c.15b) reads these; nothing here posts. Investment deduction is not modelled.
    'depreciation_classes' => [
        ['key' => 'ke_hotel_building', 'label' => 'Hotel buildings', 'method' => 'straight_line', 'first_year_bp' => 5000, 'annual_bp' => 2500, 'source' => 'ita', 'secondary' => false],
        ['key' => 'ke_manufacturing_building', 'label' => 'Manufacturing buildings', 'method' => 'straight_line', 'first_year_bp' => 5000, 'annual_bp' => 2500, 'source' => 'ita', 'secondary' => false],
        ['key' => 'ke_hospital_building', 'label' => 'Hospital buildings', 'method' => 'straight_line', 'first_year_bp' => 5000, 'annual_bp' => 2500, 'source' => 'ita', 'secondary' => false],
        ['key' => 'ke_manufacturing_machinery', 'label' => 'Manufacturing machinery', 'method' => 'straight_line', 'first_year_bp' => 5000, 'annual_bp' => 2500, 'source' => 'ita', 'secondary' => false],
        ['key' => 'ke_hospital_equipment', 'label' => 'Hospital equipment', 'method' => 'straight_line', 'first_year_bp' => 5000, 'annual_bp' => 2500, 'source' => 'ita', 'secondary' => false],
        ['key' => 'ke_ships', 'label' => 'Ships', 'method' => 'straight_line', 'first_year_bp' => 5000, 'annual_bp' => 2500, 'source' => 'ita', 'secondary' => false],
        ['key' => 'ke_aircraft', 'label' => 'Aircraft', 'method' => 'straight_line', 'first_year_bp' => 5000, 'annual_bp' => 2500, 'source' => 'ita', 'secondary' => false],
        ['key' => 'ke_vehicles', 'label' => 'Motor vehicles', 'method' => 'straight_line', 'first_year_bp' => null, 'annual_bp' => 2500, 'source' => 'ita', 'secondary' => false],
        ['key' => 'ke_earth_moving', 'label' => 'Heavy earth-moving equipment', 'method' => 'straight_line', 'first_year_bp' => null, 'annual_bp' => 2500, 'source' => 'ita', 'secondary' => false],
        ['key' => 'ke_computers', 'label' => 'Computers', 'method' => 'straight_line', 'first_year_bp' => null, 'annual_bp' => 2500, 'source' => 'ita', 'secondary' => false],
        ['key' => 'ke_software', 'label' => 'Software', 'method' => 'straight_line', 'first_year_bp' => null, 'annual_bp' => 2500, 'source' => 'ita', 'secondary' => false],
        ['key' => 'ke_furniture', 'label' => 'Furniture and fittings', 'method' => 'straight_line', 'first_year_bp' => null, 'annual_bp' => 1000, 'source' => 'ita', 'secondary' => false],
        ['key' => 'ke_telecoms', 'label' => 'Telecommunications equipment', 'method' => 'straight_line', 'first_year_bp' => null, 'annual_bp' => 1000, 'source' => 'ita', 'secondary' => false],
        ['key' => 'ke_other_machinery', 'label' => 'Other machinery', 'method' => 'straight_line', 'first_year_bp' => null, 'annual_bp' => 1000, 'source' => 'ita', 'secondary' => false],
        ['key' => 'ke_fibre_optic', 'label' => 'Fibre-optic cable rights', 'method' => 'straight_line', 'first_year_bp' => null, 'annual_bp' => 1000, 'source' => 'ita', 'secondary' => false],
        ['key' => 'ke_spectrum', 'label' => 'Spectrum licences', 'method' => 'straight_line', 'first_year_bp' => null, 'annual_bp' => 1000, 'source' => 'ita', 'secondary' => false],
        ['key' => 'ke_commercial_building', 'label' => 'Commercial buildings', 'method' => 'straight_line', 'first_year_bp' => null, 'annual_bp' => 1000, 'source' => 'pwc', 'secondary' => true,
            'note' => 'Secondary source: verify against the Income Tax Act before relying on this rate.'],
    ],

    // A few accounts added to the chart. `code8` is used when the company's chart uses 8-digit codes, `code4` otherwise. An account
    // whose code exists is left alone.
    'chart_additions' => [
        ['code8' => '13050000', 'code4' => '1260', 'name' => 'Withholding VAT receivable', 'type' => 'asset', 'subtype' => 'Tax Receivable', 'normal' => 'debit', 'description' => 'VAT withheld by customers at source, to be credited against output VAT.'],
        ['code8' => '13060000', 'code4' => '1265', 'name' => 'Withholding tax receivable', 'type' => 'asset', 'subtype' => 'Tax Receivable', 'normal' => 'debit', 'description' => 'Income tax withheld from our receipts by customers, creditable against income tax.'],
        ['code8' => '23070000', 'code4' => '2125', 'name' => 'VAT withheld payable (appointed agent)', 'type' => 'liability', 'subtype' => 'Tax Payable', 'normal' => 'credit', 'description' => 'VAT withheld from suppliers as an appointed agent (2 of the 16 points) and owed to the tax authority.'],
    ],

    // Notes shown where they apply: `return` under the tax return, `write_off` on the write-off screen.
    'notes' => [
        ['where' => 'return', 'text' => 'Box numbers of the KRA iTax VAT return are not mapped here. They stay unmapped until checked against the iTax form.'],
        ['where' => 'write_off', 'text' => 'Bad-debt relief (VAT Act s.31) is a refund application made after 3 years. It is not calculated here: do it by hand.'],
    ],
];
