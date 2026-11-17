<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Shared\DomainRuleViolation;
use InvalidArgumentException;

/**
 * A plan version's price list: `{CUR: {monthly: int|null, annual: int|null}}` in integer minor units, for the three
 * billing currencies only (G9). A null price means "custom quote" (enterprise). Floats are refused, never rounded.
 */
final class Prices
{
    public const array CURRENCIES = ['USD', 'KES', 'EUR'];

    public const array INTERVALS = ['monthly', 'annual'];

    /** @param  array<array-key, mixed>  $json */
    public static function of(array $json, string $currency, string $interval): ?int
    {
        if (! in_array($interval, self::INTERVALS, true)) {
            throw new InvalidArgumentException("Unknown billing interval {$interval}.");
        }
        $row = $json[$currency] ?? null;
        $value = is_array($row) ? ($row[$interval] ?? null) : null;

        return is_int($value) ? $value : null;
    }

    /** @return array<string, array{monthly: int|null, annual: int|null}> */
    public static function validate(mixed $prices): array
    {
        if (! is_array($prices) || $prices === [] || array_is_list($prices)) {
            throw self::invalid('Prices must be a map of currency to {monthly, annual}.');
        }
        foreach ($prices as $currency => $row) {
            if (! in_array($currency, self::CURRENCIES, true)) {
                throw self::invalid('Prices are only supported in '.implode(', ', self::CURRENCIES).'.');
            }
            if (! is_array($row) || count($row) !== 2 || ! array_key_exists('monthly', $row) || ! array_key_exists('annual', $row)) {
                throw self::invalid("{$currency} needs exactly a monthly and an annual price.");
            }
            foreach ($row as $interval => $amount) {
                if ($amount !== null && (! is_int($amount) || $amount < 0)) {
                    throw self::invalid("{$currency} {$interval} must be a whole number of minor units, zero or more.");
                }
            }
        }

        /** @var array<string, array{monthly: int|null, annual: int|null}> $prices */
        return $prices;
    }

    /**
     * Add-on unit prices: `{CUR: int}`.
     *
     * @return array<string, int>
     */
    public static function validateFlat(mixed $prices): array
    {
        if (! is_array($prices) || $prices === [] || array_is_list($prices)) {
            throw self::invalid('Prices must be a map of currency to amount.');
        }
        $clean = [];
        foreach ($prices as $currency => $amount) {
            if (! in_array($currency, self::CURRENCIES, true)) {
                throw self::invalid('Prices are only supported in '.implode(', ', self::CURRENCIES).'.');
            }
            if (! is_int($amount) || $amount < 0) {
                throw self::invalid("{$currency} must be a whole number of minor units, zero or more.");
            }
            $clean[$currency] = $amount;
        }

        return $clean;
    }

    private static function invalid(string $message): DomainRuleViolation
    {
        return DomainRuleViolation::single('plan_prices_invalid', $message);
    }
}
