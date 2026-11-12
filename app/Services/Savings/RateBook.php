<?php

namespace App\Services\Savings;

use App\Models\SavingsProduct;
use App\Models\SavingsProductRate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A product's interest rates over time (design §1, §5), with tiers (gap
 * round S2). Rates are append-only, so a figure computed for a past date
 * reads the same later.
 *
 * - **Ladder.** The rate set in force on a date is the rows sharing the
 *   latest `effective_from` on or before it. Within that date, the newest
 *   rate post wins whole (the rows sharing the newest row's
 *   `rate_post_id`), so a same-date correction replaces the ladder. Rows
 *   with no post id (before tiers) keep the highest id per minimum balance.
 *   `min_balance` null is the base tier, which covers every balance below
 *   the first tier; a ladder of one base row is a plain single rate, as
 *   before tiers.
 * - **Tier for a balance.** The highest tier whose minimum the balance
 *   reaches (whole-balance tiering); without a balance, the base tier.
 * - Time deposit rates (a term) never carry a minimum balance.
 */
class RateBook
{
    /**
     * The rate in force on a date ("2.5000"), or null when none is yet. With
     * `$balance` (centavos), the tier that balance reaches; without it, the
     * base tier.
     */
    public function rateOn(int $productId, ?int $termDays, string $date, ?int $balance = null): ?string
    {
        $tier = self::tierFor($this->ladderOn($productId, $termDays, $date), $balance);

        return $tier === null ? null : $tier['annual_rate'];
    }

    /**
     * The ladder in force on a date, base tier first and then by minimum
     * balance; empty when no rate is in force yet.
     *
     * @return list<array{id: int, min_balance: string|null, annual_rate: string, effective_from: string}>
     */
    public function ladderOn(int $productId, ?int $termDays, string $date): array
    {
        $latest = $this->rows($productId, $termDays)->where('effective_from', '<=', $date)->max('effective_from');

        if ($latest === null) {
            return [];
        }

        $day = CarbonImmutable::parse((string) $latest)->toDateString();

        return self::ladder($this->rows($productId, $termDays)->whereDate('effective_from', $day)->orderBy('id')->get());
    }

    /**
     * The rate over each part of [$start, $end], split where the ladder
     * changes; a stretch before the first rate reads 0.0000. Each segment's
     * `rate` is its base tier's. A segment whose ladder has tiers above the
     * base also carries the whole ladder (`tiers`, base first), so
     * InterestCalculator can pick each day's tier inside the one segment.
     *
     * @return list<array{from: string, to: string, rate: string, tiers?: list<array{min_balance: string|null, rate: string}>}>
     */
    public function segments(int $productId, ?int $termDays, string $start, string $end): array
    {
        $from = CarbonImmutable::parse($start)->startOfDay();
        $to = CarbonImmutable::parse($end)->startOfDay();

        if ($from->greaterThan($to)) {
            return [];
        }

        $byDate = $this->history($productId, $termDays)
            ->groupBy(fn (SavingsProductRate $rate) => $rate->effective_from->toDateString())
            ->map(fn (Collection $rows) => self::ladder($rows))
            ->all();

        $segments = [];
        $current = [];

        foreach ($byDate as $date => $ladder) {
            if ($date <= $from->toDateString()) {
                $current = $ladder;
            }
        }

        $segmentStart = $from;

        foreach ($byDate as $date => $ladder) {
            $changes = CarbonImmutable::parse($date)->startOfDay();

            if ($changes->lessThanOrEqualTo($from) || $changes->greaterThan($to)) {
                continue;
            }

            $segments[] = self::segment($segmentStart, $changes->subDay(), $current);
            $segmentStart = $changes;
            $current = $ladder;
        }

        $segments[] = self::segment($segmentStart, $to, $current);

        return $segments;
    }

    /**
     * The rate in force per term on a date: one row for a regular product,
     * one per offered term for a time deposit product (a term with no rate
     * yet is left out). `annual_rate` and `id` are the base tier's; `tiers`
     * is the whole ladder, base first.
     *
     * @return list<array{id: int, term_days: int|null, annual_rate: string, effective_from: string, tiers: list<array{min_balance: string|null, annual_rate: string, effective_from: string}>}>
     */
    public function current(SavingsProduct $product, string $date): array
    {
        $terms = $product->isTimeDeposit() ? array_map('intval', $product->term_options_days ?? []) : [null];
        $history = $product->relationLoaded('rates') ? $product->rates : $product->rates()->get();
        $current = [];

        foreach ($terms as $term) {
            $inForce = $history->filter(fn (SavingsProductRate $row) => $row->term_days === $term && $row->effective_from->toDateString() <= $date);
            $latest = $inForce->map(fn (SavingsProductRate $row) => $row->effective_from->toDateString())->max();

            if ($latest === null) {
                continue;
            }

            $ladder = self::ladder($inForce->filter(fn (SavingsProductRate $row) => $row->effective_from->toDateString() === $latest)->sortBy('id'));
            $base = self::tierFor($ladder, null);

            if ($base === null) {
                continue;
            }

            $current[] = [
                'id' => $base['id'],
                'term_days' => $term,
                'annual_rate' => $base['annual_rate'],
                'effective_from' => $latest,
                'tiers' => array_map(fn (array $tier) => [
                    'min_balance' => $tier['min_balance'],
                    'annual_rate' => $tier['annual_rate'],
                    'effective_from' => $tier['effective_from'],
                ], $ladder),
            ];
        }

        return $current;
    }

    /**
     * The tier a balance (centavos) reaches: the highest whose minimum it
     * reaches, else the base tier; the base tier when no balance is given.
     * Null when the ladder is empty, or has no base tier and the balance is
     * below every tier.
     *
     * @template T of array{min_balance: string|null, annual_rate: string}
     *
     * @param  list<T>  $ladder  base first, then ascending
     * @return T|null
     */
    public static function tierFor(array $ladder, ?int $balance): ?array
    {
        $chosen = null;

        foreach ($ladder as $tier) {
            if ($tier['min_balance'] === null) {
                $chosen = $tier;
            } elseif ($balance !== null && $balance >= SavingsMath::centavos($tier['min_balance'])) {
                $chosen = $tier;
            }
        }

        return $chosen;
    }

    /**
     * One date's rows as a ladder, base tier first, then by minimum balance:
     * the rows of the newest post when the newest row has a post id, else
     * the highest id per minimum balance.
     *
     * @param  Collection<int, SavingsProductRate>  $rows  one date's rows, in id order
     * @return list<array{id: int, min_balance: string|null, annual_rate: string, effective_from: string}>
     */
    private static function ladder(Collection $rows): array
    {
        $post = $rows->last()?->rate_post_id;

        if ($post !== null) {
            $rows = $rows->filter(fn (SavingsProductRate $row) => $row->rate_post_id === $post);
        }

        $byMinimum = [];

        foreach ($rows as $row) {
            $byMinimum[$row->min_balance ?? ''] = [
                'id' => $row->id,
                'min_balance' => $row->min_balance === null ? null : SavingsMath::pesos(SavingsMath::centavos($row->min_balance)),
                'annual_rate' => $row->annual_rate,
                'effective_from' => $row->effective_from->toDateString(),
            ];
        }

        $ladder = array_values($byMinimum);
        usort($ladder, static fn (array $a, array $b): int => ($a['min_balance'] === null ? -1 : SavingsMath::centavos($a['min_balance']))
            <=> ($b['min_balance'] === null ? -1 : SavingsMath::centavos($b['min_balance'])));

        return $ladder;
    }

    /**
     * @param  list<array{id: int, min_balance: string|null, annual_rate: string, effective_from: string}>  $ladder
     * @return array{from: string, to: string, rate: string, tiers?: list<array{min_balance: string|null, rate: string}>}
     */
    private static function segment(CarbonImmutable $from, CarbonImmutable $to, array $ladder): array
    {
        $segment = ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rate' => self::tierFor($ladder, null)['annual_rate'] ?? '0.0000'];

        if (collect($ladder)->contains(fn (array $tier) => $tier['min_balance'] !== null)) {
            $segment['tiers'] = array_map(fn (array $tier) => ['min_balance' => $tier['min_balance'], 'rate' => $tier['annual_rate']], $ladder);
        }

        return $segment;
    }

    /** @return Builder<SavingsProductRate> */
    private function rows(int $productId, ?int $termDays): Builder
    {
        return SavingsProductRate::query()
            ->where('product_id', $productId)
            ->when($termDays === null, fn ($q) => $q->whereNull('term_days'), fn ($q) => $q->where('term_days', $termDays));
    }

    /** @return Collection<int, SavingsProductRate> */
    private function history(int $productId, ?int $termDays)
    {
        return $this->rows($productId, $termDays)
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();
    }
}
