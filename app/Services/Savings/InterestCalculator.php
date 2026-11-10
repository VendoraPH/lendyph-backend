<?php

declare(strict_types=1);

namespace App\Services\Savings;

use Carbon\CarbonImmutable;

/**
 * Average daily balance interest, Actual/365 Fixed (design §5). Pure: the
 * caller loads the balances (DailyBalances), the rates (RateBook) and the
 * tax rate (TaxBook) and hands them in.
 *
 * - balance(d) = the opening balance plus every value-dated change up to d,
 *   in centavos; the day's balance is the closing balance of that day.
 * - S_seg = the sum of the daily balances over a rate segment (centavo-days);
 *   r_seg = its rate in units (percent × 10⁴).
 * - gross = roundHalfUp(Σ S_seg × r_seg, 365 × 10⁶): a leap year still
 *   divides by 365.
 * - ADB = roundHalfUp(ΣS, days); below the minimum the gross is 0 with a
 *   reason.
 * - tax = roundHalfUp(gross × t, 10⁶); net = gross − tax.
 * - The numerator is refused (OverflowException) when it would leave a
 *   64-bit integer.
 */
final class InterestCalculator
{
    public const DAY_BASIS = 365;

    public const SKIP_BELOW_MINIMUM_ADB = 'below_minimum_adb';

    public const SKIP_NO_DAYS = 'no_days';

    /**
     * @param  int  $opening  the balance before `$start`, in centavos
     * @param  array<string, int>  $deltas  signed change per value date (Y-m-d), in centavos
     * @param  list<array{from: string, to: string, rate: string}>  $segments  the rates over [$start, $end]; a day no segment covers earns 0%
     * @param  int  $minimumAdb  the product's minimum average daily balance, in centavos
     * @param  string  $taxRate  the withholding tax percent
     * @return array{
     *     days: int,
     *     balance_days: int,
     *     average_daily_balance: int,
     *     rate: string,
     *     rate_segments: list<array{from: string, to: string, rate: string, balance_days: int}>,
     *     gross: int,
     *     tax_rate: string,
     *     tax: int,
     *     net: int,
     *     skip_reason: string|null,
     * }
     */
    public function calculate(int $opening, array $deltas, string $start, string $end, array $segments, int $minimumAdb, string $taxRate): array
    {
        $from = CarbonImmutable::parse($start)->startOfDay();
        $to = CarbonImmutable::parse($end)->startOfDay();
        $taxUnits = SavingsMath::rateUnits($taxRate);
        $taxString = SavingsMath::rateString($taxUnits);

        if ($from->greaterThan($to)) {
            return $this->nothing(0, 0, 0, '0.0000', [], $taxString, self::SKIP_NO_DAYS);
        }

        $daily = $this->dailyBalances($opening, $deltas, $from, $to);
        $days = count($daily);
        $balanceDays = 0;

        foreach ($daily as $balance) {
            $balanceDays = SavingsMath::add($balanceDays, $balance);
        }

        $numerator = 0;
        $used = [];
        $lastRate = '0.0000';

        foreach ($this->covering($segments, $from, $to) as $segment) {
            $sum = 0;

            for ($day = $segment['from']; $day->lessThanOrEqualTo($segment['to']); $day = $day->addDay()) {
                $sum = SavingsMath::add($sum, $daily[$day->toDateString()]);
            }

            $units = SavingsMath::rateUnits($segment['rate']);
            $numerator = SavingsMath::add($numerator, SavingsMath::multiply($sum, $units));
            $lastRate = SavingsMath::rateString($units);
            $used[] = ['from' => $segment['from']->toDateString(), 'to' => $segment['to']->toDateString(), 'rate' => $lastRate, 'balance_days' => $sum];
        }

        $adb = SavingsMath::roundHalfUp($balanceDays, $days);

        if ($adb < $minimumAdb) {
            return $this->nothing($days, $balanceDays, $adb, $lastRate, $used, $taxString, self::SKIP_BELOW_MINIMUM_ADB);
        }

        $gross = max(0, SavingsMath::roundHalfUp($numerator, self::DAY_BASIS * SavingsMath::RATE_DENOMINATOR));
        $tax = $gross === 0 ? 0 : SavingsMath::roundHalfUp(SavingsMath::multiply($gross, $taxUnits), SavingsMath::RATE_DENOMINATOR);

        return [
            'days' => $days,
            'balance_days' => $balanceDays,
            'average_daily_balance' => $adb,
            'rate' => $lastRate,
            'rate_segments' => $used,
            'gross' => $gross,
            'tax_rate' => $taxString,
            'tax' => $tax,
            'net' => $gross - $tax,
            'skip_reason' => null,
        ];
    }

    /**
     * Time deposit interest for a whole term at one rate (design §5):
     * roundHalfUp(P × r × days, 365 × 10⁶).
     */
    public function simple(int $principal, string $rate, int $days): int
    {
        return SavingsMath::roundHalfUp(
            SavingsMath::multiply(SavingsMath::multiply($principal, SavingsMath::rateUnits($rate)), $days),
            self::DAY_BASIS * SavingsMath::RATE_DENOMINATOR,
        );
    }

    /**
     * Each day's closing balance over [$from, $to].
     *
     * @param  array<string, int>  $deltas
     * @return array<string, int>
     */
    private function dailyBalances(int $opening, array $deltas, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $balance = $opening;

        foreach ($deltas as $date => $delta) {
            if ($date < $from->toDateString()) {
                $balance = SavingsMath::add($balance, $delta);
            }
        }

        $daily = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $balance = SavingsMath::add($balance, $deltas[$day->toDateString()] ?? 0);
            $daily[$day->toDateString()] = $balance;
        }

        return $daily;
    }

    /**
     * The segments clipped to [$from, $to], in date order.
     *
     * @param  list<array{from: string, to: string, rate: string}>  $segments
     * @return list<array{from: CarbonImmutable, to: CarbonImmutable, rate: string}>
     */
    private function covering(array $segments, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $clipped = [];

        foreach ($segments as $segment) {
            $start = CarbonImmutable::parse($segment['from'])->startOfDay()->max($from);
            $finish = CarbonImmutable::parse($segment['to'])->startOfDay()->min($to);

            if ($start->lessThanOrEqualTo($finish)) {
                $clipped[] = ['from' => $start, 'to' => $finish, 'rate' => $segment['rate']];
            }
        }

        usort($clipped, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        return $clipped;
    }

    /**
     * @param  list<array{from: string, to: string, rate: string, balance_days: int}>  $segments
     */
    private function nothing(int $days, int $balanceDays, int $adb, string $rate, array $segments, string $taxRate, string $reason): array
    {
        return [
            'days' => $days,
            'balance_days' => $balanceDays,
            'average_daily_balance' => $adb,
            'rate' => $rate,
            'rate_segments' => $segments,
            'gross' => 0,
            'tax_rate' => $taxRate,
            'tax' => 0,
            'net' => 0,
            'skip_reason' => $reason,
        ];
    }
}
