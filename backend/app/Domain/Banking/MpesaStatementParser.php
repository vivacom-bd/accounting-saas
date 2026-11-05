<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use DateTimeImmutable;
use DateTimeZone;

/**
 * M-Pesa statement CSV (plan 3c.29). NEEDS CONFIRMATION AGAINST A REAL BUSINESS EXPORT: the column names below come from third-party
 * copies of personal statements ("Receipt No", "Completion Time", "Details", "Transaction Status", "Paid in", "Withdrawn", "Balance")
 * and have not been seen on a business statement. So columns are found by header NAME, tolerant of case, spaces, punctuation and a few
 * synonyms; where a header is not recognised the account's saved mapping (`columns`: logical name → header name or 0-based index) fills in;
 * and if that does not either, the file is refused naming the missing column and the headers that were found. Nothing is guessed by position.
 *
 * Rules:
 *  - rows whose status is not "completed" are SKIPPED and reported with the line, receipt, status and reason, never silently dropped;
 *  - times are East Africa Time (UTC+3, no daylight saving): the statement date of a row is the date as written in EAT, not converted;
 *  - the receipt number is the line's `reference` and its identity: a line is the same line when account, receipt and amount are the same,
 *    whatever its wording or position, so importing again adds nothing;
 *  - money is exact decimal text to integer minor units (AmountParser), never a float; "Paid in" is money in, "Withdrawn" money out
 *    (shown positive or negative);
 *  - whether charges appear as their own rows is unknown, so every row is a line as it is: nothing is merged or invented.
 */
final class MpesaStatementParser
{
    /** The statement's own timezone. */
    public const string TIMEZONE = 'Africa/Nairobi';

    /** logical column → accepted header names, normalised (lower case, letters and digits only). */
    private const array SYNONYMS = [
        'receipt' => ['receiptno', 'receiptnumber', 'receipt', 'transactionid', 'transactionreceipt', 'mpesareceipt', 'mpesareceiptno'],
        'time' => ['completiontime', 'completedtime', 'completiondate', 'transactiontime', 'datetime', 'date', 'time'],
        'details' => ['details', 'description', 'transactiondetails', 'narrative', 'particulars'],
        'status' => ['transactionstatus', 'status'],
        'paid_in' => ['paidin', 'moneyin', 'creditamount', 'credit', 'amountin', 'received', 'in'],
        'withdrawn' => ['withdrawn', 'withdrawal', 'moneyout', 'paidout', 'debitamount', 'debit', 'amountout', 'out'],
        'balance' => ['balance', 'runningbalance', 'accountbalance'],
        'amount' => ['amount', 'transactionamount'],
    ];

    private const array TIME_FORMATS = [
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd.m.Y H:i:s', 'd.m.Y H:i',
        'Y-m-d', 'd-m-Y', 'd/m/Y',
    ];

    private const array COMPLETED = ['completed', 'complete', 'success', 'successful'];

    /** @param array<string, mixed> $mapping the account's saved mapping: `columns` (logical → header name or index), `skip_rows` */
    public function parse(string $content, array $mapping, int $scale = 2): ParsedStatement
    {
        $records = CsvStatementParser::records($content, ',');
        if ($records === []) {
            throw StatementFormatException::at(null, 'statement_empty', 'The file has no rows.');
        }
        [$headerAt, $header] = $this->header($records, $mapping);
        $cols = $this->columns($header, $mapping);

        $body = array_slice($records, $headerAt + 1);
        if (count($body) > StatementLimits::MAX_LINES) {
            throw StatementFormatException::at(null, 'statement_too_large', 'The file has more than '.StatementLimits::MAX_LINES.' lines.');
        }

        $zone = new DateTimeZone(self::TIMEZONE);
        $lines = [];
        $stamps = [];
        $skipped = [];
        $errors = [];
        foreach ($body as [$lineNo, $row]) {
            if (count(array_filter($row, fn (string $c): bool => trim($c) !== '')) === 0) {
                continue;
            }
            $get = fn (?int $c): ?string => $c === null || ! isset($row[$c]) || trim($row[$c]) === '' ? null : trim($row[$c]);
            $receipt = $get($cols['receipt']);
            try {
                $status = $cols['status'] === null ? null : $get($cols['status']);
                if ($cols['status'] !== null && ($status === null || ! in_array(preg_replace('/[^a-z]/', '', mb_strtolower($status)), self::COMPLETED, true))) {
                    $skipped[] = ['line' => $lineNo, 'reference' => $receipt, 'status' => $status, 'reason' => $status === null ? 'status_missing' : 'not_completed'];

                    continue;
                }
                if ($receipt === null) {
                    throw new \InvalidArgumentException('A completed row has no receipt number.');
                }
                $when = $this->when($get($cols['time']) ?? throw new \InvalidArgumentException('The completion time is missing.'), $zone);
                $amount = $this->amount($get($cols['paid_in']), $get($cols['withdrawn']), $get($cols['amount']), $cols, $scale);
                if ($amount === 0) {
                    $skipped[] = ['line' => $lineNo, 'reference' => $receipt, 'status' => $status, 'reason' => 'zero_amount'];

                    continue;
                }
                $balance = $get($cols['balance']);
                $line = new ParsedLine(
                    $when->format('Y-m-d'), $amount, $get($cols['details']) ?? '', null, $receipt, null,
                    $balance === null ? null : AmountParser::parse($balance, 'us', $scale), $lineNo, dedupeKey: 'mpesa:'.mb_strtolower($receipt).':'.$amount,
                );
                $lines[] = $line;
                $stamps[] = $when->getTimestamp();
            } catch (\InvalidArgumentException $e) {
                $errors[] = ['line' => $lineNo, 'code' => 'line_invalid', 'message' => $e->getMessage()];
                if (count($errors) >= 20) {
                    break;
                }
            }
        }
        if ($errors !== []) {
            throw StatementFormatException::many($errors);
        }
        if ($lines === []) {
            throw StatementFormatException::at(null, 'statement_empty', $skipped === [] ? 'The file has no statement lines.' : 'No row in the file is completed ('.count($skipped).' skipped).');
        }

        $warnings = ['mpesa_layout_unconfirmed'];
        if ($skipped !== []) {
            $warnings[] = 'mpesa_rows_skipped';
        }
        if ($cols['status'] === null) {
            $warnings[] = 'mpesa_no_status_column';
        }

        // Balances: oldest row first, by time (files are often newest first).
        $opening = $closing = null;
        if ($cols['balance'] !== null) {
            $order = array_keys($stamps);
            usort($order, fn (int $a, int $b): int => [$stamps[$a], $a] <=> [$stamps[$b], $b]);
            $first = $lines[$order[0]];
            $last = $lines[$order[count($order) - 1]];
            if ($first->balanceAfter !== null && $last->balanceAfter !== null) {
                $opening = $first->balanceAfter - $first->amount;
                $closing = $last->balanceAfter;
            } else {
                $warnings[] = 'balance_column_incomplete';
            }
        }
        $dates = array_map(fn (ParsedLine $l): string => $l->txnDate, $lines);

        return new ParsedStatement('mpesa', $lines, $opening, $closing, min($dates), max($dates), null, null, $warnings, $skipped);
    }

    /**
     * The header row: the first row (in the first 60) that names a receipt column and a money column; else the row the saved mapping points at.
     *
     * @param  list<array{0: int, 1: list<string>}>  $records
     * @param  array<string, mixed>  $mapping
     * @return array{0: int, 1: list<string>}
     */
    private function header(array $records, array $mapping): array
    {
        foreach (array_slice($records, 0, 60, true) as $i => [, $row]) {
            $names = array_map(self::norm(...), $row);
            $hasReceipt = array_intersect($names, self::SYNONYMS['receipt']) !== [];
            $hasMoney = array_intersect($names, [...self::SYNONYMS['paid_in'], ...self::SYNONYMS['withdrawn'], ...self::SYNONYMS['amount']]) !== [];
            if ($hasReceipt && $hasMoney) {
                return [$i, $row];
            }
        }
        $skip = max(0, (int) ($mapping['skip_rows'] ?? 0));
        if (isset($mapping['columns']) && isset($records[$skip])) {
            return [$skip, $records[$skip][1]];
        }

        throw StatementFormatException::at(null, 'mpesa_headers_not_found', 'No header row with a receipt column and paid in / withdrawn columns was found. This reader is unconfirmed against a real M-Pesa business export: map the columns for this account.');
    }

    /**
     * @param  list<string>  $header
     * @param  array<string, mixed>  $mapping
     * @return array{receipt: int, time: int, details: int, status: int|null, paid_in: int|null, withdrawn: int|null, balance: int|null, amount: int|null}
     */
    private function columns(array $header, array $mapping): array
    {
        $names = array_map(self::norm(...), $header);
        /** @var array<string, int|string|null> $saved */
        $saved = is_array($mapping['columns'] ?? null) ? $mapping['columns'] : [];
        $found = [];
        foreach (self::SYNONYMS as $logical => $accepted) {
            $pos = null;
            foreach ($accepted as $name) {
                $i = array_search($name, $names, true);
                if ($i !== false) {
                    $pos = $i;
                    break;
                }
            }
            if ($pos === null && ($saved[$logical] ?? '') !== '') {
                $ref = $saved[$logical];
                $pos = is_int($ref) || ctype_digit((string) $ref) ? (int) $ref : (($i = array_search(self::norm((string) $ref), $names, true)) === false ? null : $i);
            }
            $found[$logical] = $pos;
        }
        // 'date' and 'time' are loose synonyms: a bare "Date" column must not shadow an explicit "Completion Time".
        $missing = array_keys(array_filter(['receipt' => $found['receipt'], 'time' => $found['time'], 'details' => $found['details']], fn (?int $p): bool => $p === null));
        if ($found['paid_in'] === null && $found['withdrawn'] === null && $found['amount'] === null) {
            $missing[] = 'paid_in / withdrawn';
        }
        if ($missing !== []) {
            throw StatementFormatException::at(null, 'mpesa_columns_missing', 'Could not find the M-Pesa column(s): '.implode(', ', $missing).'. Headers in the file: '.implode(', ', array_filter(array_map('trim', $header), fn (string $h): bool => $h !== '')).'. This reader is unconfirmed against a real export: save a column mapping for this account.');
        }

        /** @var array{receipt: int, time: int, details: int, status: int|null, paid_in: int|null, withdrawn: int|null, balance: int|null, amount: int|null} $found */
        return $found;
    }

    private static function norm(string $header): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($header)));
    }

    private function when(string $raw, DateTimeZone $zone): DateTimeImmutable
    {
        $raw = trim((string) preg_replace('/\s*(EAT|UTC\+3|\+03:?00)$/i', '', trim($raw)));
        foreach (self::TIME_FORMATS as $format) {
            $d = DateTimeImmutable::createFromFormat('!'.$format, $raw, $zone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($d !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                $year = (int) $d->format('Y');
                if ($year < 1990 || $year > 2100) {
                    break;
                }

                return $d;
            }
        }

        throw new \InvalidArgumentException("'{$raw}' is not a date and time this reader knows (year-month-day or day-month-year, East Africa Time).");
    }

    /**
     * @param  array{amount: int|null}  $cols
     */
    private function amount(?string $in, ?string $out, ?string $signed, array $cols, int $scale): int
    {
        if ($cols['amount'] !== null && $in === null && $out === null) {
            return $signed === null ? throw new \InvalidArgumentException('The amount is missing.') : AmountParser::parse($signed, 'us', $scale);
        }
        $paidIn = $in === null ? 0 : AmountParser::parse($in, 'us', $scale);
        $withdrawn = $out === null ? 0 : abs(AmountParser::parse($out, 'us', $scale));
        if ($paidIn < 0) {
            throw new \InvalidArgumentException("'{$in}' in the paid in column is negative.");
        }
        if ($paidIn > 0 && $withdrawn > 0) {
            throw new \InvalidArgumentException('A row has both a paid in and a withdrawn amount.');
        }

        return $paidIn - $withdrawn;
    }
}
