<?php

namespace App\Enums;

/**
 * The period a loan's `interest_rate` is quoted per ("3% per month").
 *
 * The payment frequencies minus `upon_maturity`, which is a repayment shape
 * rather than a period. `monthly` is the default and the basis every rate
 * entered before this column existed was charged on.
 */
enum InterestRateFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case BiWeekly = 'bi_weekly';
    case SemiMonthly = 'semi_monthly';
    case Monthly = 'monthly';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public static function rule(): string
    {
        return 'in:'.implode(',', self::values());
    }
}
