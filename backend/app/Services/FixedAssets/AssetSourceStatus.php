<?php

declare(strict_types=1);

namespace App\Services\FixedAssets;

use App\Domain\Ledger\JournalStatus;
use App\Domain\Trade\InvoiceKind;
use App\Domain\Workflow\DocumentStatus;
use App\Models\FixedAssets\FixedAsset;
use App\Models\Ledger\Journal;
use App\Models\Trade\Invoice;
use App\Models\Trade\InvoiceLine;
use Illuminate\Support\Facades\DB;

/**
 * Tells whether the bill or journal an active asset was capitalised from still stands. An asset's cost came from a posted line;
 * if that journal or bill is later reversed or voided, or a bill is credited in full on the asset's account by posted debit notes,
 * the register still carries a cost the ledger no longer has. This only flags it. It never reverses or disposes of anything: how to
 * deal with it (dispose of or write off the asset, or restore the source) is the accountant's decision.
 *
 * Reads only. A disposed asset is not flagged: it has been dealt with.
 */
final class AssetSourceStatus
{
    public const string REVERSED = 'source_reversed';

    public const string CREDITED = 'source_credited';

    /**
     * What is wrong with each asset's source, by asset id; assets whose source stands (or that have none) are absent.
     *
     * @param  iterable<FixedAsset>  $assets
     * @return array<string, array{code: string, message: string, document_type: string, document_id: string, document_number: string|null}>
     */
    public function warnings(iterable $assets): array
    {
        $journalAssets = [];
        $billAssets = [];
        foreach ($assets as $asset) {
            if ($asset->status !== 'active' || $asset->source_id === null) {
                continue;
            }
            if ($asset->source_type === 'journal_line' && $asset->source_journal_id !== null) {
                $journalAssets[$asset->id] = $asset->source_journal_id;
            } elseif ($asset->source_type === 'bill_line') {
                $billAssets[$asset->id] = $asset->source_id;
            }
        }

        return $this->journals($journalAssets) + $this->bills($billAssets);
    }

    /** Sets `sourceWarning` on each asset in one go, so a list does not ask per asset. @param iterable<FixedAsset> $assets */
    public function attach(iterable $assets): void
    {
        $assets = is_array($assets) ? $assets : iterator_to_array($assets, false);
        $found = $this->warnings($assets);
        foreach ($assets as $asset) {
            $asset->sourceWarning = $found[$asset->id] ?? null;
            $asset->sourceWarningLoaded = true;
        }
    }

    /**
     * @return array{code: string, message: string, document_type: string, document_id: string, document_number: string|null}|null
     */
    public function warningFor(FixedAsset $asset): ?array
    {
        return $this->warnings([$asset])[$asset->id] ?? null;
    }

    /**
     * @param  array<string, string>  $assetJournal  asset id → source journal id
     * @return array<string, array{code: string, message: string, document_type: string, document_id: string, document_number: string|null}>
     */
    private function journals(array $assetJournal): array
    {
        if ($assetJournal === []) {
            return [];
        }
        $gone = Journal::query()->whereIn('id', array_values(array_unique($assetJournal)))->whereIn('status', [JournalStatus::Reversed->value, JournalStatus::Void->value])->get()->keyBy('id');
        $out = [];
        foreach ($assetJournal as $assetId => $journalId) {
            $journal = $gone->get($journalId);
            if ($journal !== null) {
                $out[$assetId] = $this->warning(self::REVERSED, 'journal', $journal->id, $journal->number, "journal {$journal->number}", $journal->status === JournalStatus::Void ? 'voided' : 'reversed');
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $assetLine  asset id → source bill line id
     * @return array<string, array{code: string, message: string, document_type: string, document_id: string, document_number: string|null}>
     */
    private function bills(array $assetLine): array
    {
        if ($assetLine === []) {
            return [];
        }
        $lines = InvoiceLine::query()->whereIn('id', array_values(array_unique($assetLine)))->get()->keyBy('id');
        $bills = Invoice::query()->whereIn('id', $lines->pluck('invoice_id')->unique()->all())->get()->keyBy('id');
        $credited = $this->creditedOnAccount($bills->where('status', DocumentStatus::Posted)->keys()->all());

        $out = [];
        foreach ($assetLine as $assetId => $lineId) {
            $line = $lines->get($lineId);
            $bill = $line === null ? null : $bills->get($line->invoice_id);
            if ($line === null || $bill === null) {
                continue;
            }
            $label = 'bill '.($bill->number ?? '');
            if (in_array($bill->status, [DocumentStatus::Reversed, DocumentStatus::Void], true)) {
                $out[$assetId] = $this->warning(self::REVERSED, 'bill', $bill->id, $bill->number, $label, $bill->status === DocumentStatus::Void ? 'voided' : 'reversed');
            } else {
                $key = $bill->id.'|'.$line->account_id;
                if (isset($credited[$key]) && $credited[$key]['credited'] >= $credited[$key]['billed']) {
                    $out[$assetId] = $this->warning(self::CREDITED, 'bill', $bill->id, $bill->number, $label, 'credited in full by debit notes');
                }
            }
        }

        return $out;
    }

    /**
     * Per posted bill and account: what the bill charged to the account and what posted debit notes against it took back (document
     * currency, net of discount and before tax; a note is in the bill's currency).
     *
     * @param  list<string>  $billIds
     * @return array<string, array{billed: int, credited: int}>
     */
    private function creditedOnAccount(array $billIds): array
    {
        if ($billIds === []) {
            return [];
        }
        $billed = DB::table('invoice_lines')->whereIn('invoice_id', $billIds)->groupBy('invoice_id', 'account_id')
            ->selectRaw('invoice_id, account_id, sum(line_total_fc) as total')->get();
        $credits = DB::table('invoice_lines as l')->join('invoices as n', 'n.id', '=', 'l.invoice_id')
            ->whereIn('n.related_invoice_id', $billIds)->where('n.kind', InvoiceKind::DebitNote->value)->where('n.status', DocumentStatus::Posted->value)
            ->groupBy('n.related_invoice_id', 'l.account_id')->selectRaw('n.related_invoice_id as invoice_id, l.account_id, sum(l.line_total_fc) as total')->get();

        $out = [];
        foreach ($billed as $b) {
            $out[$b->invoice_id.'|'.$b->account_id] = ['billed' => (int) $b->total, 'credited' => 0];
        }
        foreach ($credits as $c) {
            $key = $c->invoice_id.'|'.$c->account_id;
            if (isset($out[$key])) {
                $out[$key]['credited'] = (int) $c->total;
            }
        }

        return $out;
    }

    /** @return array{code: string, message: string, document_type: string, document_id: string, document_number: string|null} */
    private function warning(string $code, string $type, string $id, ?string $number, string $label, string $what): array
    {
        return [
            'code' => $code,
            'message' => "The {$label} this asset was capitalised from has been {$what}, so its cost may no longer be in the ledger. Check it, then dispose of or write off the asset, or restore the source.",
            'document_type' => $type,
            'document_id' => $id,
            'document_number' => $number,
        ];
    }
}
