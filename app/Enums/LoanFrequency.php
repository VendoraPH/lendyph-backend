<?php

namespace App\Enums;

enum LoanFrequency: string
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
