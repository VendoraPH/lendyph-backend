<?php

namespace App\Services\CsvImport;

use App\Enums\LoanFrequency;
use App\Services\LoanTermSchedule;

/**
 * The period a CSV loan's interest rate is quoted in.
 *
 * The importer stores the file's rate as it is, and the period it is stored
 * in is the one LoanTermSchedule::fromPeriodCount() gives the imported loan:
 * monthly for monthly and upon-maturity loans, otherwise the payment
 * frequency. This reads that rule from fromPeriodCount() itself, so the
 * bounds check and the stored loan can never disagree about it.
 */
final class CsvRatePeriod
{
    /**
     * The rate period for a payment frequency, or null when the frequency is
     * not one the importer accepts (the rate can't be compared then).
     */
    public static function forPaymentFrequency(mixed $paymentFrequency): ?string
    {
        if (! is_string($paymentFrequency) || ! in_array($paymentFrequency, LoanFrequency::values(), true)) {
            return null;
        }

        return LoanTermSchedule::fromPeriodCount(1, $paymentFrequency)['interest_rate_frequency'];
    }

    /**
     * Days in one rate period, on the 30-day-month convention, or null when
     * the period is unknown.
     */
    public static function days(?string $ratePeriod): ?int
    {
        return $ratePeriod === null ? null : (LoanTermSchedule::PERIOD_DAYS[$ratePeriod] ?? null);
    }
}
