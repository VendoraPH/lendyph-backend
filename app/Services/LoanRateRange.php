<?php

namespace App\Services;

use App\Enums\InterestRateFrequency;
use App\Models\Loan;
use App\Models\LoanProduct;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/**
 * The rule a loan's changed interest rate is held to, shared by the ordinary
 * edit (LoanService::updateLoan()) and the Chair's edit during approval
 * (LoanApprovalEditService) (finish run round 4), and by an in-place
 * restructure's new rate (LoanAdjustmentService, finish run round 5).
 *
 * A loan keeps the period its rate is quoted per (`interest_rate_frequency`),
 * copied from its product when the loan was made. The product may quote its
 * rates per another period since, per year instead of per month, say. A new
 * rate would then be stored per the loan's period but checked against a range
 * quoted per the product's, so it is refused in words; the loan's own rate
 * stays, and an edit that leaves it alone still saves. Otherwise the rate must
 * sit inside the product's range, compared exactly (never as floats), and the
 * refusal names the period.
 *
 * Creating a loan keeps its own check (LoanService::createLoan()): a new loan
 * takes its product's period, so the two can't differ there.
 */
final class LoanRateRange
{
    /**
     * Refuse `$rate` for `$loan` unless its product still quotes rates per the
     * loan's period and the rate sits between the product's minimum (its rate
     * when it has none) and its rate.
     *
     * @param  string  $when  where the change is refused, ending the sentence ("during approval"), or nothing
     * @param  string  $field  the field the refusal is reported on: the request's own key for the rate
     *
     * @throws ValidationException on `$field`
     */
    public static function assertChangeable(Loan $loan, LoanProduct $product, string $rate, string $when = '', string $field = 'interest_rate'): void
    {
        if ($loan->interest_rate_frequency !== $product->interest_rate_frequency) {
            throw ValidationException::withMessages([
                $field => ['This loan\'s rate is quoted '.self::period($loan->interest_rate_frequency)
                    .', but its product now quotes rates '.self::period($product->interest_rate_frequency)
                    .', so the rate can\'t be changed'.($when === '' ? '' : ' '.$when).'.'],
            ]);
        }

        $minimum = BigDecimal::of((string) ($product->min_interest_rate ?? $product->interest_rate));
        $maximum = BigDecimal::of((string) $product->interest_rate);
        $value = BigDecimal::of($rate);

        if ($value->isLessThan($minimum) || $value->isGreaterThan($maximum)) {
            throw ValidationException::withMessages([
                $field => ['The interest rate must be between '.self::percent((string) $minimum).' and '.self::percent((string) $maximum).' '
                    .self::period($product->interest_rate_frequency).' for this product.'],
            ]);
        }
    }

    /** "per month", "per year": the period a rate is quoted per. */
    public static function period(?InterestRateFrequency $frequency): string
    {
        return match ($frequency) {
            InterestRateFrequency::Daily => 'per day',
            InterestRateFrequency::Weekly => 'per week',
            InterestRateFrequency::BiWeekly => 'per 2 weeks',
            InterestRateFrequency::SemiMonthly => 'per half month',
            InterestRateFrequency::Annual => 'per year',
            default => 'per month',
        };
    }

    /** "2.5%", every decimal the rate has and no trailing zero: rates and fees are never rounded. */
    public static function percent(string $rate): string
    {
        return BigDecimal::of($rate)->strippedOfTrailingZeros().'%';
    }
}
