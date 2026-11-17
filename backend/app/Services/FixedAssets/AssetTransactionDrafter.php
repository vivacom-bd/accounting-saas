<?php

declare(strict_types=1);

namespace App\Services\FixedAssets;

use App\Contracts\IdGenerator;
use App\Domain\FixedAssets\AssetOutcome;
use App\Models\FixedAssets\AssetCategory;
use App\Models\FixedAssets\AssetTransaction;
use App\Models\FixedAssets\AssetTransactionLine;
use App\Models\FixedAssets\FixedAsset;
use App\Support\Actor;

/** What drafting an asset transaction and editing a draft share: its size and its stored lines. */
final class AssetTransactionDrafter
{
    public function __construct(private readonly IdGenerator $ids) {}

    /**
     * @param  list<array{asset: FixedAsset, category: AssetCategory, input: int, outcome: AssetOutcome}>  $plan
     */
    public function size(string $kind, array $plan): int
    {
        return match ($kind) {
            'disposal' => $plan[0]['asset']->cost,
            'revaluation' => array_sum(array_map(static fn (array $p): int => abs((int) ($p['outcome']->facts['change'] ?? 0)), $plan)),
            default => $plan[0]['input'],
        };
    }

    /**
     * Stores one line per planned asset and answers how many.
     *
     * @param  list<array{asset: FixedAsset, category: AssetCategory, input: int, outcome: AssetOutcome}>  $plan
     */
    public function writeLines(Actor $actor, AssetTransaction $tx, array $plan): int
    {
        $no = 0;
        foreach ($plan as $p) {
            (new AssetTransactionLine)->forceFill([
                'id' => $this->ids->next(), 'tenant_id' => $actor->tenantId, 'company_id' => $tx->company_id, 'asset_transaction_id' => $tx->id,
                'line_no' => ++$no, 'asset_id' => $p['asset']->id, 'input_amount' => $p['input'], 'figures' => $p['outcome']->toArray(),
            ])->save();
        }

        return $no;
    }
}
