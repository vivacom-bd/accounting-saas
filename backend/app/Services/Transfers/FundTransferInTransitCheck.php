<?php

declare(strict_types=1);

namespace App\Services\Transfers;

use App\Contracts\PeriodCloseCheck;
use App\Domain\Fiscal\CheckOutcome;
use App\Models\Fiscal\FiscalPeriod;
use App\Models\Organisation\Company;
use App\Services\Trade\PostingAccountResolver;
use Illuminate\Support\Facades\DB;

/**
 * TRANSFERS_IN_TRANSIT: a fund transfer sent in or before the period and not received by its last day is money that has left one
 * account and not reached the other. That can be true and fine (a bank transfer sent on the 30th), so it warns and does not
 * block: the outcome passes with `detail.warning = true`, and shows the funds-in-transit balance next to the open transfers so the
 * two can be compared. A difference means a transfer was reversed or posted another way, and is worth a look before closing.
 */
final class FundTransferInTransitCheck implements PeriodCloseCheck
{
    public function __construct(private readonly PostingAccountResolver $accounts) {}

    public function code(): string
    {
        return 'TRANSFERS_IN_TRANSIT';
    }

    public function run(FiscalPeriod $period): CheckOutcome
    {
        if ($period->kind !== 'regular') {
            return new CheckOutcome($this->code(), true, 'The adjustment period has no transfers in transit.');
        }
        $end = $period->ends_on->format('Y-m-d');
        // Sent on or before the last day, posted, and either never received or received later, and not reversed by the last day.
        $open = DB::table('fund_transfers as t')
            ->leftJoin('journals as rj', 'rj.id', '=', 't.send_reversal_journal_id')
            ->where('t.company_id', $period->company_id)->whereIn('t.status', ['posted', 'reversed'])
            ->where('t.send_date', '<=', $end)
            ->where(fn ($q) => $q->whereNull('t.received_on')->orWhere('t.received_on', '>', $end))
            ->where(fn ($q) => $q->whereNull('rj.id')->orWhere('rj.journal_date', '>', $end))
            ->orderBy('t.send_date')->get(['t.id', 't.number', 't.send_date', 't.send_base', 't.from_currency', 't.from_amount_fc']);
        $count = $open->count();
        $total = (int) $open->sum('send_base');

        $balance = null;
        $company = Company::query()->find($period->company_id);
        $accountId = $company === null ? null : $this->accounts->named($company, 'bank_in_transit');
        if ($accountId !== null) {
            $balance = (int) DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')
                ->where('l.company_id', $period->company_id)->where('l.account_id', $accountId)->whereIn('j.status', ['posted', 'reversed'])
                ->where('j.journal_date', '<=', $end)->sum(DB::raw('l.debit - l.credit'));
        }

        $detail = [
            'warning' => $count > 0,
            'open_transfers' => $count,
            'open_total' => $total,
            'in_transit_balance' => $balance,
            'difference' => $balance === null ? null : $balance - $total,
            'transfers' => $open->map(fn ($t): array => [
                'id' => (string) $t->id, 'number' => $t->number, 'send_date' => (string) $t->send_date, 'amount' => (int) $t->send_base,
            ])->all(),
        ];

        return new CheckOutcome(
            $this->code(), true,
            $count === 0 ? 'No fund transfer is still in transit at the end of the period.'
                : "{$count} fund transfer(s) were sent and not received by the end of the period. This is a warning, not a block.",
            $detail,
        );
    }
}
