<?php

namespace App\Services\Savings;

use App\Models\SavingsProduct;
use App\Models\SavingsProductRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A product's interest rates over time (design §1, §5). The rate in force on
 * a date is the row with the latest `effective_from` on or before it, ties to
 * the highest id. Rates are append-only, so a figure computed for a past date
 * reads the same later.
 */
class RateBook
{
    /** The rate in force on a date ("2.5000"), or null when none is yet. */
    public function rateOn(int $productId, ?int $termDays, string $date): ?string
    {
        return SavingsProductRate::query()
            ->where('product_id', $productId)
            ->when($termDays === null, fn ($q) => $q->whereNull('term_days'), fn ($q) => $q->where('term_days', $termDays))
            ->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('annual_rate');
    }

    /**
     * The rate over each part of [$start, $end], split where it changes; a
     * stretch before the first rate reads 0.0000.
     *
     * @return list<array{from: string, to: string, rate: string}>
     */
    public function segments(int $productId, ?int $termDays, string $start, string $end): array
    {
        $from = CarbonImmutable::parse($start)->startOfDay();
        $to = CarbonImmutable::parse($end)->startOfDay();

        if ($from->greaterThan($to)) {
            return [];
        }

        $byDate = [];

        foreach ($this->history($productId, $termDays) as $rate) {
            $byDate[$rate->effective_from->toDateString()] = $rate->annual_rate;
        }

        $segments = [];
        $current = '0.0000';

        foreach ($byDate as $date => $rate) {
            if ($date <= $from->toDateString()) {
                $current = $rate;
            }
        }

        $segmentStart = $from;

        foreach ($byDate as $date => $rate) {
            $changes = CarbonImmutable::parse($date)->startOfDay();

            if ($changes->lessThanOrEqualTo($from) || $changes->greaterThan($to)) {
                continue;
            }

            $segments[] = ['from' => $segmentStart->toDateString(), 'to' => $changes->subDay()->toDateString(), 'rate' => $current];
            $segmentStart = $changes;
            $current = $rate;
        }

        $segments[] = ['from' => $segmentStart->toDateString(), 'to' => $to->toDateString(), 'rate' => $current];

        return $segments;
    }

    /**
     * The rate in force per term on a date: one row for a regular product,
     * one per offered term for a time deposit product (a term with no rate
     * yet is left out).
     *
     * @return list<array{id: int, term_days: int|null, annual_rate: string, effective_from: string}>
     */
    public function current(SavingsProduct $product, string $date): array
    {
        $terms = $product->isTimeDeposit() ? array_map('intval', $product->term_options_days ?? []) : [null];
        $history = $product->relationLoaded('rates') ? $product->rates : $product->rates()->get();
        $current = [];

        foreach ($terms as $term) {
            $rate = $history
                ->filter(fn (SavingsProductRate $row) => $row->term_days === $term && $row->effective_from->toDateString() <= $date)
                ->sortBy(fn (SavingsProductRate $row) => $row->effective_from->toDateString().'#'.str_pad((string) $row->id, 20, '0', STR_PAD_LEFT))
                ->last();

            if ($rate !== null) {
                $current[] = ['id' => $rate->id, 'term_days' => $term, 'annual_rate' => $rate->annual_rate, 'effective_from' => $rate->effective_from->toDateString()];
            }
        }

        return $current;
    }

    /** @return Collection<int, SavingsProductRate> */
    private function history(int $productId, ?int $termDays)
    {
        return SavingsProductRate::query()
            ->where('product_id', $productId)
            ->when($termDays === null, fn ($q) => $q->whereNull('term_days'), fn ($q) => $q->where('term_days', $termDays))
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();
    }
}
