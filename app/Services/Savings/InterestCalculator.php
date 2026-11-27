<?php

declare(strict_types=1);

namespace App\Services\Savings;

use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
 *   64-bit integer, and overlapping rate segments are refused (422, in
 *   words): a day earns one rate.
 * - Tiers (gap round S2): a segment may carry its whole ladder (`tiers`,
 *   RateBook). Each day's closing balance then earns the rate of the highest
 *   tier whose minimum it reaches, the base tier below the first one
 *   (whole-balance tiering), and S is summed per tier: N adds S_tier ×
 *   r_tier. The ladder is one segment, never overlapping ones. The line's
 *   segment records the ladder (`tiers`) and, per tier used, its days and
 *   centavo-days (`by_tier`), so the gross can be worked out again from the
 *   line; its `rate` is the tier the segment's last day earned. A ladder of
 *   only a base tier reads exactly like a single rate.
 */
final class InterestCalculator
{
    public const DAY_BASIS = 365;

    public const SKIP_BELOW_MINIMUM_ADB = 'below_minimum_adb';

    public const SKIP_NO_DAYS = 'no_days';

    /**
     * @param  int  $opening  the balance before `$start`, in centavos
     * @param  array<string, int>  $deltas  signed change per value date (Y-m-d), in centavos
     * @param  list<array{from: string, to: string, rate: string, tiers?: list<array{min_balance: string|null, rate: string}>}>  $segments  the rates over [$start, $end]; a day no segment covers earns 0%
     * @param  int  $minimumAdb  the product's minimum average daily balance, in centavos
     * @param  string  $taxRate  the withholding tax percent
     * @return array{
     *     days: int,
     *     balance_days: int,
     *     average_daily_balance: int,
     *     rate: string,
     *     rate_segments: list<array{from: string, to: string, rate: string, balance_days: int, tiers?: list<array<string, mixed>>, by_tier?: list<array<string, mixed>>}>,
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
            if ($segment['tiers'] !== null) {
                [$segmentNumerator, $record] = $this->tiered($segment, $daily);
                $numerator = SavingsMath::add($numerator, $segmentNumerator);
                $lastRate = $record['rate'];
                $used[] = $record;

                continue;
            }

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
     * One tiered segment: each day's closing balance earns the highest tier
     * whose minimum it reaches. Returns the segment's numerator and its
     * recorded line part.
     *
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, rate: string, tiers: list<array{min_balance: string|null, min: int, units: int}>}  $segment
     * @param  array<string, int>  $daily
     * @return array{0: int, 1: array{from: string, to: string, rate: string, balance_days: int, tiers: list<array{min_balance: string|null, rate: string}>, by_tier: list<array{min_balance: string|null, rate: string, days: int, balance_days: int}>}}
     */
    private function tiered(array $segment, array $daily): array
    {
        $tiers = $segment['tiers'];
        $byTier = [];
        $sum = 0;
        $last = 0;

        for ($day = $segment['from']; $day->lessThanOrEqualTo($segment['to']); $day = $day->addDay()) {
            $balance = $daily[$day->toDateString()];
            $index = 0;

            foreach ($tiers as $i => $tier) {
                if ($balance >= $tier['min']) {
                    $index = $i;
                }
            }

            $byTier[$index] ??= ['days' => 0, 'balance_days' => 0];
            $byTier[$index]['days']++;
            $byTier[$index]['balance_days'] = SavingsMath::add($byTier[$index]['balance_days'], $balance);
            $sum = SavingsMath::add($sum, $balance);
            $last = $index;
        }

        ksort($byTier);
        $numerator = 0;
        $recorded = [];

        foreach ($byTier as $index => $usage) {
            $numerator = SavingsMath::add($numerator, SavingsMath::multiply($usage['balance_days'], $tiers[$index]['units']));
            $recorded[] = [
                'min_balance' => $tiers[$index]['min_balance'],
                'rate' => SavingsMath::rateString($tiers[$index]['units']),
                'days' => $usage['days'],
                'balance_days' => $usage['balance_days'],
            ];
        }

        return [$numerator, [
            'from' => $segment['from']->toDateString(),
            'to' => $segment['to']->toDateString(),
            'rate' => SavingsMath::rateString($tiers[$last]['units']),
            'balance_days' => $sum,
            'tiers' => array_map(fn (array $tier) => ['min_balance' => $tier['min_balance'], 'rate' => SavingsMath::rateString($tier['units'])], $tiers),
            'by_tier' => $recorded,
        ]];
    }

    /**
     * The segments clipped to [$from, $to], in date order. A segment's ladder
     * is read into exact figures (minimums in centavos, rates in units), base
     * tier first; a ladder of only a base tier becomes that single rate, and
     * a ladder with no base tier or a repeated minimum is refused (422).
     *
     * @param  list<array{from: string, to: string, rate: string, tiers?: list<array{min_balance: string|null, rate: string}>}>  $segments
     * @return list<array{from: CarbonImmutable, to: CarbonImmutable, rate: string, tiers: list<array{min_balance: string|null, min: int, units: int}>|null}>
     */
    private function covering(array $segments, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $clipped = [];

        foreach ($segments as $segment) {
            $start = CarbonImmutable::parse($segment['from'])->startOfDay()->max($from);
            $finish = CarbonImmutable::parse($segment['to'])->startOfDay()->min($to);

            if ($start->lessThanOrEqualTo($finish)) {
                $tiers = $this->ladder($segment['tiers'] ?? null, $start);
                $rate = $segment['rate'];

                if ($tiers !== null && count($tiers) === 1) {
                    $rate = SavingsMath::rateString($tiers[0]['units']);
                    $tiers = null;
                }

                $clipped[] = ['from' => $start, 'to' => $finish, 'rate' => $rate, 'tiers' => $tiers];
            }
        }

        usort($clipped, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        for ($i = 1; $i < count($clipped); $i++) {
            if ($clipped[$i]['from']->lessThanOrEqualTo($clipped[$i - 1]['to'])) {
                throw new HttpException(422,
                    'The interest rates overlap on '.$clipped[$i]['from']->format('M j, Y').': a day can earn only one rate.');
            }
        }

        return $clipped;
    }

    /**
     * A segment's ladder in exact figures, base tier first, or null when it
     * has none.
     *
     * @param  list<array{min_balance: string|null, rate: string}>|null  $tiers
     * @return list<array{min_balance: string|null, min: int, units: int}>|null
     */
    private function ladder(?array $tiers, CarbonImmutable $from): ?array
    {
        if ($tiers === null || $tiers === []) {
            return null;
        }

        $read = array_map(static function (array $tier): array {
            $minimum = $tier['min_balance'] === null ? null : SavingsMath::centavos($tier['min_balance']);

            return [
                'min_balance' => $minimum === null ? null : SavingsMath::pesos($minimum),
                'min' => $minimum ?? PHP_INT_MIN,
                'units' => SavingsMath::rateUnits($tier['rate']),
            ];
        }, $tiers);

        usort($read, static fn (array $a, array $b): int => $a['min'] <=> $b['min']);
        $minimums = array_column($read, 'min');

        if ($read[0]['min_balance'] !== null || count(array_unique($minimums)) !== count($minimums)) {
            throw new HttpException(422,
                'The interest rates from '.$from->format('M j, Y').' need exactly one base rate (no minimum balance) and one rate per minimum balance.');
        }

        return $read;
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
