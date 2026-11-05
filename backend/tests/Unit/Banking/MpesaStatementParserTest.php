<?php

declare(strict_types=1);

use App\Domain\Banking\LineHash;
use App\Domain\Banking\MpesaStatementParser;
use App\Domain\Banking\ParsedStatement;
use App\Domain\Banking\StatementFormatException;
use App\Domain\Banking\StatementParsers;

/*
 * M-Pesa statement reader (plan 3c.29). The column names are from third-party copies of PERSONAL statements and are unconfirmed for
 * business accounts; these tests pin how the reader behaves on a file I wrote, not on a real export.
 */

function mpesa(string $csv, array $mapping = []): ParsedStatement
{
    return (new MpesaStatementParser)->parse($csv, $mapping, 2);
}

function mpesaFixture(): string
{
    return (string) file_get_contents(__DIR__.'/../../fixtures/bank/mpesa_march.csv');
}

it('reads the completed rows, finds the header after the preamble, and reports what it skipped', function (): void {
    $s = mpesa(mpesaFixture());
    expect($s->format)->toBe('mpesa')->and($s->lines)->toHaveCount(3)->and($s->periodFrom)->toBe('2026-03-10')->and($s->periodTo)->toBe('2026-03-12')
        ->and(array_map(fn ($l) => [$l->reference, $l->amount], $s->lines))->toBe([['SCD3R4T5Y6', 150000], ['SCC2Q3R4T5', -500000], ['SCA0N1P2Q3', 220000]])
        ->and($s->lines[1]->description)->toBe('Pay Bill to 400200 - Landlord Ltd')
        ->and($s->skipped)->toBe([
            ['line' => 8, 'reference' => 'SCB1P2Q3R4', 'status' => 'FAILED', 'reason' => 'not_completed'],
            ['line' => 9, 'reference' => 'SC90M0N1P2', 'status' => 'PENDING', 'reason' => 'not_completed'],
        ])
        ->and($s->warnings)->toContain('mpesa_layout_unconfirmed')->toContain('mpesa_rows_skipped');
});

it('takes the date as written in East Africa Time, not converted to UTC', function (): void {
    $s = mpesa(mpesaFixture());
    // 00:30 EAT on 10 March is 21:30 UTC on 9 March: the statement date stays 10 March.
    expect(collect($s->lines)->firstWhere('reference', 'SCA0N1P2Q3')->txnDate)->toBe('2026-03-10')->and(MpesaStatementParser::TIMEZONE)->toBe('Africa/Nairobi');
});

it('works out the opening and closing balance from the oldest and newest completed rows, whatever the file order', function (): void {
    $s = mpesa(mpesaFixture());
    expect($s->openingBalance)->toBe(1030000)->and($s->closingBalance)->toBe(900000)->and($s->openingBalance + $s->sum())->toBe($s->closingBalance)
        ->and(StatementParsers::balanceWarnings($s))->toBe([]);
});

it('is tolerant of header case, spaces, punctuation and synonyms', function (): void {
    $csv = "receipt number,completion time,TRANSACTION STATUS,Details,Money In,Money Out\r\nAB1,2026-03-01 10:00:00,Completed,Sale,\"1,000.50\",\r\nAB2,2026-03-02 10:00,completed,Rent,,\"200.05\"\r\n";
    $s = mpesa("\xEF\xBB\xBF".$csv);
    expect(array_map(fn ($l) => $l->amount, $s->lines))->toBe([100050, -20005])->and($s->openingBalance)->toBeNull();
});

it('parses money exactly, with no float: ten cents and a long total do not drift', function (): void {
    $csv = "Receipt No,Completion Time,Details,Transaction Status,Paid in,Withdrawn\nA,2026-03-01 10:00:00,x,COMPLETED,0.10,\nB,2026-03-01 10:00:01,x,COMPLETED,0.20,\nC,2026-03-01 10:00:02,x,COMPLETED,\"99,999,999,999.99\",\n";
    $s = mpesa($csv);
    expect($s->sum())->toBe(10 + 20 + 9_999_999_999_999)->and($s->lines[2]->amount)->toBe(9_999_999_999_999);
    expect(fn () => mpesa("Receipt No,Completion Time,Details,Transaction Status,Paid in,Withdrawn\nA,2026-03-01 10:00:00,x,COMPLETED,0.105,\n"))->toThrow(StatementFormatException::class, 'more than 2 decimals');
});

it('refuses, naming the missing columns and the headers found, when headers are not recognised and no mapping helps', function (): void {
    $csv = "Ref,When,What,State,In,Out\nA,2026-03-01 10:00:00,x,COMPLETED,1.00,\n";
    try {
        mpesa($csv);
        $this->fail('should have refused');
    } catch (StatementFormatException $e) {
        expect($e->errorCode)->toBe('mpesa_headers_not_found');
    }
    // A header row exists but one column is unknown.
    $csv = "Receipt No,Completion Time,Narration X,Transaction Status,Paid in,Withdrawn\nA,2026-03-01 10:00:00,x,COMPLETED,1.00,\n";
    try {
        mpesa($csv);
        $this->fail('should have refused');
    } catch (StatementFormatException $e) {
        expect($e->errorCode)->toBe('mpesa_columns_missing')->and($e->getMessage())->toContain('details')->toContain('Narration X');
    }
});

it('uses a saved mapping for headers it does not know', function (): void {
    $csv = "Receipt No,Completion Time,Narration X,Transaction Status,Paid in,Withdrawn\nA,2026-03-01 10:00:00,Sale,COMPLETED,1.00,\n";
    $s = mpesa($csv, ['columns' => ['details' => 'Narration X']]);
    expect($s->lines[0]->description)->toBe('Sale');
    // By position, when the header row cannot be recognised at all.
    $odd = "Ref,When,What,State,In,Out\nA,2026-03-01 10:00:00,Sale,COMPLETED,1.00,\n";
    $s = mpesa($odd, ['skip_rows' => 0, 'columns' => ['receipt' => 'Ref', 'time' => 'When', 'details' => 'What', 'status' => 'State', 'paid_in' => 'In', 'withdrawn' => 'Out']]);
    expect($s->lines[0]->reference)->toBe('A')->and($s->lines[0]->amount)->toBe(100);
});

it('names the line of a completed row it cannot read, and refuses a row with both amounts', function (): void {
    $head = "Receipt No,Completion Time,Details,Transaction Status,Paid in,Withdrawn\n";
    foreach ([
        [",2026-03-01 10:00:00,x,COMPLETED,1.00,\n", 'no receipt number'],
        ["A,not a date,x,COMPLETED,1.00,\n", 'not a date'],
        ["A,2026-03-01 10:00:00,x,COMPLETED,1.00,2.00\n", 'both a paid in and a withdrawn'],
        ["A,2026-03-01 10:00:00,x,COMPLETED,abc,\n", 'not a valid amount'],
    ] as [$row, $why]) {
        try {
            mpesa($head.$row);
            $this->fail("should have refused: {$why}");
        } catch (StatementFormatException $e) {
            expect($e->getMessage())->toContain('Line 2')->toContain($why);
        }
    }
});

it('skips a zero-amount completed row and says so; a file with nothing completed is refused', function (): void {
    $s = mpesa("Receipt No,Completion Time,Details,Transaction Status,Paid in,Withdrawn\nA,2026-03-01 10:00:00,x,COMPLETED,0.00,\nB,2026-03-01 10:00:00,x,COMPLETED,5.00,\n");
    expect($s->lines)->toHaveCount(1)->and($s->skipped[0]['reason'])->toBe('zero_amount');
    expect(fn () => mpesa("Receipt No,Completion Time,Details,Transaction Status,Paid in,Withdrawn\nA,2026-03-01 10:00:00,x,FAILED,5.00,\n"))->toThrow(StatementFormatException::class, 'No row in the file is completed');
});

it('warns when there is no status column, since non-completed rows cannot then be told apart', function (): void {
    $s = mpesa("Receipt No,Completion Time,Details,Paid in,Withdrawn\nA,2026-03-01 10:00:00,x,5.00,\n");
    expect($s->warnings)->toContain('mpesa_no_status_column');
});

it('identifies a line by receipt and amount, so rewording or reordering does not make it new', function (): void {
    $a = mpesa(mpesaFixture());
    $reworded = str_replace('Pay Bill to 400200 - Landlord Ltd', 'Pay Bill 400200 LANDLORD', mpesaFixture());
    $reordered = mpesa(implode("\n", array_merge(array_slice(explode("\n", $reworded), 0, 5), array_reverse(array_filter(array_slice(explode("\n", $reworded), 5))))));
    $h = fn (ParsedStatement $s) => collect(LineHash::all('acct', $s->lines))->sort()->values()->all();
    expect($h(mpesa($reworded)))->toBe($h($a))->and($h($reordered))->toBe($h($a));
    // The same receipt with another amount is another line; other accounts never collide.
    expect(LineHash::all('other', $a->lines))->not->toBe(LineHash::all('acct', $a->lines));
});

it('is never picked by sniffing: it has to be chosen', function (): void {
    expect(StatementParsers::sniff(mpesaFixture()))->toBe('csv')->and(StatementParsers::FORMATS)->toContain('mpesa');
});
