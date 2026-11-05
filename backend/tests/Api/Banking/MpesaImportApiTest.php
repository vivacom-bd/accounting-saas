<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\BankingApi;
use Tests\Support\MakesApiCalls;
use Tests\Support\TradeApi;

/*
 * M-Pesa statement import (plan 3c.29) through the statement endpoint with format=mpesa. The sample is a file I wrote from third-party
 * descriptions of PERSONAL statements; the layout is unconfirmed against a real business export, and the response says so.
 */

uses(MakesApiCalls::class, TradeApi::class, BankingApi::class);

beforeEach(function (): void {
    $this->setUpBanking();
    $this->mpesa = (string) file_get_contents(__DIR__.'/../../fixtures/bank/mpesa_march.csv');
});

it('imports the completed rows with their receipt numbers, reports the skipped ones, and flags the layout as unconfirmed', function (): void {
    $r = $this->importStatement('mpesa.csv', ['format' => 'mpesa'], content: $this->mpesa)->assertCreated();
    expect($r->json('data.lines_imported'))->toBe(3)->and($r->json('data.statement.source'))->toBe('mpesa')
        ->and($r->json('data.statement.opening_balance.amount'))->toBe(1030000)->and($r->json('data.statement.closing_balance.amount'))->toBe(900000)
        ->and($r->json('data.warnings'))->toContain('mpesa_layout_unconfirmed')->toContain('mpesa_rows_skipped')
        ->and($r->json('data.skipped_rows'))->toBe([
            ['line' => 8, 'reference' => 'SCB1P2Q3R4', 'status' => 'FAILED', 'reason' => 'not_completed'],
            ['line' => 9, 'reference' => 'SC90M0N1P2', 'status' => 'PENDING', 'reason' => 'not_completed'],
        ]);
    $lines = collect($this->asPerson($this->owner)->getJson($this->api("bank-accounts/{$this->bank}/lines?limit=200"))->assertOk()->json('data'))->keyBy('reference');
    expect($lines->keys()->sort()->values()->all())->toBe(['SCA0N1P2Q3', 'SCC2Q3R4T5', 'SCD3R4T5Y6'])
        ->and($lines['SCC2Q3R4T5']['amount']['amount'])->toBe(-500000)->and($lines['SCA0N1P2Q3']['txn_date'])->toBe('2026-03-10')->and($lines['SCD3R4T5Y6']['status'])->toBe('unmatched');
});

it('adds nothing on a re-import, even of a different export that repeats some receipts', function (): void {
    $this->importStatement('mpesa.csv', ['format' => 'mpesa'], content: $this->mpesa)->assertCreated();
    $again = $this->importStatement('mpesa.csv', ['format' => 'mpesa'], content: $this->mpesa)->assertOk();
    expect($again->json('data.already_imported'))->toBeTrue()->and($again->json('data.lines_imported'))->toBe(0);

    // A later export: two known receipts (one reworded) and one new one.
    $later = "Receipt No,Completion Time,Details,Transaction Status,Paid in,Withdrawn,Balance\n"
        ."SCE4S5T6U7,2026-03-14 08:00:00,Customer Payment from 254700000001,COMPLETED,\"100.00\",,\"9,100.00\"\n"
        ."SCD3R4T5Y6,2026-03-12 21:55:12,CUSTOMER PAYMENT 254700111222 JANE,COMPLETED,\"1,500.00\",,\"9,000.00\"\n"
        ."SCC2Q3R4T5,2026-03-12 09:10:00,Pay Bill 400200,COMPLETED,,\"5,000.00\",\"7,500.00\"\n";
    $r = $this->importStatement('mpesa2.csv', ['format' => 'mpesa'], content: $later)->assertCreated();
    expect($r->json('data.lines_imported'))->toBe(1)->and($r->json('data.duplicate_lines_skipped'))->toBe(2);
    $this->inTenant(fn () => expect(DB::table('bank_statement_lines')->count())->toBe(4));
});

it('refuses a file whose headers it cannot find, saying which, and does not guess; a saved mapping fills the gap', function (): void {
    $odd = str_replace('Details', 'Narration X', $this->mpesa);
    $r = $this->importStatement('odd.csv', ['format' => 'mpesa'], content: $odd)->assertStatus(422);
    expect($r->json('violations.0.code'))->toBe('mpesa_columns_missing')->and($r->json('violations.0.message'))->toContain('details')->toContain('Narration X');

    $ok = $this->importStatement('odd.csv', ['format' => 'mpesa', 'mapping' => json_encode(['columns' => ['details' => 'Narration X']]), 'save_mapping' => '1'], content: $odd)->assertCreated();
    expect($ok->json('data.lines_imported'))->toBe(3);
    // The mapping is saved on the account, so the next file with these headers needs no mapping.
    $next = str_replace(['SCD3R4T5Y6', 'SCC2Q3R4T5', 'SCA0N1P2Q3'], ['ZZ1', 'ZZ2', 'ZZ3'], $odd);
    $this->importStatement('odd2.csv', ['format' => 'mpesa'], content: $next)->assertCreated()->assertJsonPath('data.lines_imported', 3);
});

it('is never chosen by auto: a file sent as auto is read as a plain CSV and asks for a mapping', function (): void {
    $this->importStatement('mpesa.csv', ['format' => 'auto'], content: $this->mpesa)->assertStatus(422)->assertJsonPath('violations.0.code', 'mapping_required');
});

it('lets a person with banking.reconcile import, and a viewer not', function (): void {
    $viewer = $this->person('Vic Viewer', ['Viewer']);
    $this->asPerson($viewer)->post($this->api("bank-accounts/{$this->bank}/statements"), ['file' => UploadedFile::fake()->createWithContent('m.csv', $this->mpesa), 'format' => 'mpesa'], [...$this->key(), 'Accept' => 'application/json'])->assertForbidden();
});
