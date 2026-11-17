<?php

declare(strict_types=1);

namespace App\Actions\Admin\Plans;

use App\Contracts\IdGenerator;
use App\Domain\Billing\Prices;
use App\Domain\Shared\DomainRuleViolation;
use App\Models\Billing\Coupon;
use App\Support\Admin\AdminActor;
use App\Support\Admin\AdminAuditWriter;
use App\Support\Admin\AdminTransaction;
use DateTimeImmutable;

/**
 * A discount code (10 §3.4): a percentage XOR a fixed amount in one currency; once, for N months, or forever.
 * Mirrored to Stripe by the catalogue sync (Task 11).
 */
final class CreateCoupon
{
    public const array DURATIONS = ['once', 'repeating', 'forever'];

    private const array RESTRICTION_KEYS = ['plan_codes', 'new_customers_only'];

    public function __construct(private readonly AdminAuditWriter $audit, private readonly IdGenerator $ids) {}

    /**
     * @param  array{code: string, percent: int|null, amount: int|null, currency: string|null, duration: string, duration_months: int|null, restrictions: array<array-key, mixed>, max_redemptions: int|null, expires_at: DateTimeImmutable|null}  $spec
     */
    public function __invoke(AdminActor $actor, array $spec): Coupon
    {
        $code = mb_strtoupper(trim($spec['code']));
        $this->assertValid($code, $spec);

        return AdminTransaction::run(function () use ($actor, $code, $spec): Coupon {
            if (Coupon::query()->whereRaw('upper(code) = ?', [$code])->exists()) {
                throw DomainRuleViolation::single('coupon_code_taken', 'A coupon with this code already exists.');
            }

            $coupon = new Coupon;
            $coupon->forceFill([
                'id' => $this->ids->next(), 'code' => $code, 'percent' => $spec['percent'], 'amount' => $spec['amount'],
                'currency' => $spec['currency'], 'duration' => $spec['duration'], 'duration_months' => $spec['duration_months'],
                'restrictions' => $spec['restrictions'], 'max_redemptions' => $spec['max_redemptions'], 'expires_at' => $spec['expires_at'],
                'is_active' => true,
            ])->save();

            $this->audit->record($actor, 'coupon.created', null, 'coupon', $coupon->id, after: [
                'code' => $code, 'percent' => $spec['percent'], 'amount' => $spec['amount'], 'currency' => $spec['currency'],
                'duration' => $spec['duration'], 'duration_months' => $spec['duration_months'], 'max_redemptions' => $spec['max_redemptions'],
            ]);

            return $coupon;
        });
    }

    /** @param  array<string, mixed>  $spec */
    private function assertValid(string $code, array $spec): void
    {
        $bad = fn (string $code, string $message): DomainRuleViolation => DomainRuleViolation::single($code, $message);
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{2,47}$/', $code) !== 1) {
            throw $bad('coupon_code_invalid', 'A coupon code is 3 to 48 letters, digits, dashes or underscores.');
        }

        $percent = $spec['percent'];
        $amount = $spec['amount'];
        $currency = $spec['currency'];
        $hasPercent = $percent !== null;
        $hasAmount = $amount !== null || $currency !== null;
        if ($hasPercent === $hasAmount) {
            throw $bad('coupon_discount_invalid', 'A coupon takes either a percentage or an amount with a currency, not both and not neither.');
        }
        if ($hasPercent && (! is_int($percent) || $percent < 1 || $percent > 100)) {
            throw $bad('coupon_discount_invalid', 'The percentage must be a whole number from 1 to 100.');
        }
        if ($hasAmount && (! is_int($amount) || $amount < 1 || ! is_string($currency) || ! in_array($currency, Prices::CURRENCIES, true))) {
            throw $bad('coupon_discount_invalid', 'An amount coupon needs minor units above zero and one of '.implode(', ', Prices::CURRENCIES).'.');
        }

        $duration = $spec['duration'];
        $months = $spec['duration_months'];
        if (! is_string($duration) || ! in_array($duration, self::DURATIONS, true)) {
            throw $bad('coupon_duration_invalid', 'Duration must be once, repeating or forever.');
        }
        if (($duration === 'repeating') !== ($months !== null) || ($months !== null && (! is_int($months) || $months < 1 || $months > 36))) {
            throw $bad('coupon_duration_invalid', 'Only a repeating coupon has a duration, from 1 to 36 months.');
        }

        $max = $spec['max_redemptions'];
        if ($max !== null && (! is_int($max) || $max < 1)) {
            throw $bad('coupon_redemptions_invalid', 'Maximum redemptions must be 1 or more.');
        }

        $restrictions = $spec['restrictions'];
        if (! is_array($restrictions) || array_diff(array_keys($restrictions), self::RESTRICTION_KEYS) !== []
            || (isset($restrictions['new_customers_only']) && ! is_bool($restrictions['new_customers_only']))
            || (isset($restrictions['plan_codes']) && (! is_array($restrictions['plan_codes']) || array_filter($restrictions['plan_codes'], 'is_string') !== $restrictions['plan_codes']))) {
            throw $bad('coupon_restrictions_invalid', 'Restrictions may only list plan_codes and new_customers_only.');
        }
    }
}
