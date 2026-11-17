<?php

declare(strict_types=1);

namespace App\Domain\Consolidation;

use App\Domain\Shared\Fx;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The rounding rules of consolidation, in one place (D16.4). Everything is integer minor units; every rounding is HALF_UP
 * (halves away from zero, like Fx::toBase), and applied once per figure.
 *
 *  - Translation is `translate()`, once per worksheet figure (and once per day for an equity movement). A translated
 *    column can then differ from zero by rounding; that difference lands in the translation reserve, which is the
 *    balancing line of the member by definition ("the difference goes to the translation reserve"), so no cent is lost.
 *  - The default period average is `dailyMean()`: the mean of the rate in force on each day of the window, rounded HALF_UP
 *    to the eight decimals rates are stored with.
 */
final class Rounding
{
    public const int RATE_SCALE = 8;

    public static function translate(int $amount, string $rate, int $fromScale, int $toScale): int
    {
        return Fx::toBase($amount, $rate, $fromScale, $toScale);
    }

    /**
     * The mean of a rate held for a number of days each: Σ(rate × days) ÷ Σ days.
     *
     * @param  non-empty-list<array{rate: string, days: int}>  $segments
     */
    public static function dailyMean(array $segments): string
    {
        $sum = BigDecimal::zero();
        $days = 0;
        foreach ($segments as $s) {
            $sum = $sum->plus(BigDecimal::of($s['rate'])->multipliedBy($s['days']));
            $days += $s['days'];
        }

        return (string) $sum->dividedBy($days, self::RATE_SCALE, RoundingMode::HalfUp);
    }
}
