<?php

declare(strict_types=1);

namespace App\Services\FixedAssets;

use App\Contracts\PeriodCloseCheck;
use App\Domain\Fiscal\CheckOutcome;
use App\Models\Fiscal\FiscalPeriod;
use App\Models\FixedAssets\FixedAsset;

/**
 * ASSET_SOURCE: an active asset acquired by the period's end whose source bill or journal was reversed, voided or credited in full.
 * It warns and never blocks (the outcome passes with `detail.warning = true`): the register may be right, for instance when the
 * bill was replaced, and what to do about it is the accountant's call. The detail names each asset and the document.
 */
final class AssetSourceCheck implements PeriodCloseCheck
{
    public function __construct(private readonly AssetSourceStatus $sources) {}

    public function code(): string
    {
        return 'ASSET_SOURCE';
    }

    public function run(FiscalPeriod $period): CheckOutcome
    {
        if ($period->kind !== 'regular') {
            return new CheckOutcome($this->code(), true, 'The adjustment period has no asset sources to check.', ['warning' => false, 'assets' => []]);
        }
        $assets = FixedAsset::query()->where('company_id', $period->company_id)->where('status', 'active')->whereNotNull('source_id')
            ->where('acquired_on', '<=', $period->ends_on->format('Y-m-d'))->orderBy('number')->get();
        $found = $this->sources->warnings($assets);
        $list = [];
        foreach ($assets as $asset) {
            $w = $found[$asset->id] ?? null;
            if ($w !== null) {
                $list[] = ['id' => $asset->id, 'number' => $asset->number, 'name' => $asset->name, 'code' => $w['code'], 'document_type' => $w['document_type'], 'document_id' => $w['document_id'], 'document_number' => $w['document_number']];
            }
        }
        $count = count($list);

        return new CheckOutcome(
            $this->code(), true,
            $count === 0 ? 'Every asset still stands on the bill or journal it was capitalised from.'
                : "{$count} asset(s) were capitalised from a bill or journal that was reversed, voided or credited in full. This is a warning, not a block: review each one.",
            ['warning' => $count > 0, 'assets' => $list],
        );
    }
}
