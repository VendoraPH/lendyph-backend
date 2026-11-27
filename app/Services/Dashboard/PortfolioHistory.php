<?php

namespace App\Services\Dashboard;

use App\Models\PortfolioDailySnapshot;
use App\Models\PortfolioSnapshotLine;
use App\Services\PortfolioRisk;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The executive dashboard's past portfolio figures (Phase 6 EB-B), read only
 * from the daily snapshots: nothing is rebuilt from today's rows ("No
 * backfill" stays).
 *
 * - A requested date reads the last snapshot on or before it, labelled with
 *   that snapshot's date ("As of Oct 15, 2026"). Before the first snapshot it
 *   reads "No snapshot for this date", with no figures.
 * - A scope narrower than the whole organisation (a branch user's branches, a
 *   branch filter or a product) reads the day's branch × product lines, so it
 *   can only use a day that has lines. A day with lines but none in the scope
 *   reads ₱0.00.
 * - The whole organisation, unfiltered, also reads days recorded before the
 *   lines, from the day's own row; the figures that row never held (PAR 60,
 *   PAR loan counts, loan counts) read null.
 *
 * Figures: `loan_count`, `active_loans`, `outstanding_principal`,
 * `total_outstanding`, `past_due_amount`, `past_due_loan_count` and
 * `par_1`..`par_90` (`amount`, `ratio` over that day's outstanding principal,
 * `loans`), the Portfolio Summary's names.
 */
final class PortfolioHistory
{
    public const NO_SNAPSHOT = 'No snapshot for this date';

    /**
     * @param  list<string>  $dates  requested days, Y-m-d
     * @param  list<int>|null  $branchIds  null for every branch
     * @return array{first_snapshot_date: string|null, points: list<array{requested_date: string, snapshot_date: string|null, state: string, label: string, source: string|null, figures: array<string, mixed>|null}>}
     */
    public function points(array $dates, ?array $branchIds, ?int $productId): array
    {
        $needsLines = $branchIds !== null || $productId !== null;

        $days = PortfolioDailySnapshot::query()
            ->where('snapshot_date', '<=', max($dates))
            ->when($needsLines, fn ($q) => $q->whereNotNull('line_count'))
            ->orderBy('snapshot_date')
            ->get();

        $chosen = [];
        foreach ($dates as $date) {
            $chosen[$date] = $days->last(fn (PortfolioDailySnapshot $day): bool => $day->snapshot_date->toDateString() <= $date);
        }

        $lineDays = collect($chosen)->filter(fn (?PortfolioDailySnapshot $day): bool => $day?->line_count !== null)
            ->map(fn (PortfolioDailySnapshot $day): string => $day->snapshot_date->toDateString())
            ->unique()->values()->all();
        $lineSums = $this->lineSums($lineDays, $branchIds, $productId);

        $points = [];
        foreach ($chosen as $date => $day) {
            if ($day === null) {
                $points[] = [
                    'requested_date' => $date,
                    'snapshot_date' => null,
                    'state' => 'no_snapshot',
                    'label' => self::NO_SNAPSHOT,
                    'source' => null,
                    'figures' => null,
                ];

                continue;
            }

            $snapshotDate = $day->snapshot_date->toDateString();
            $fromLines = $day->line_count !== null;

            $points[] = [
                'requested_date' => $date,
                'snapshot_date' => $snapshotDate,
                'state' => 'ready',
                'label' => 'As of '.$day->snapshot_date->format('M j, Y'),
                'source' => $fromLines ? 'lines' : 'organisation',
                'figures' => $fromLines
                    ? $this->figures($lineSums->get($snapshotDate))
                    : $this->figures($day->only(PortfolioSnapshotLine::FIGURES)),
            ];
        }

        return [
            'first_snapshot_date' => $days->first()?->snapshot_date->toDateString(),
            'points' => $points,
        ];
    }

    /**
     * Each day's lines in the scope, added up, by day.
     *
     * @param  list<string>  $days
     * @param  list<int>|null  $branchIds
     * @return Collection<string, array<string, mixed>>
     */
    private function lineSums(array $days, ?array $branchIds, ?int $productId): Collection
    {
        if ($days === []) {
            return collect();
        }

        $sums = implode(', ', array_map(
            fn (string $figure): string => "COALESCE(SUM({$figure}), 0) as {$figure}",
            PortfolioSnapshotLine::FIGURES,
        ));

        return PortfolioSnapshotLine::query()
            ->toBase()
            ->whereIn('snapshot_date', $days)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->when($productId !== null, fn ($q) => $q->where('loan_product_id', $productId))
            ->groupBy('snapshot_date')
            ->selectRaw("snapshot_date, {$sums}")
            ->get()
            ->mapWithKeys(fn (object $row) => [Carbon::parse($row->snapshot_date)->toDateString() => (array) $row]);
    }

    /**
     * A point's figures from line sums or a day's row; a missing figure reads
     * null, and a day with lines but none in the scope reads zero.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>
     */
    private function figures(?array $values): array
    {
        $values ??= array_fill_keys(PortfolioSnapshotLine::FIGURES, 0);

        $money = fn (string $figure): ?float => $values[$figure] === null ? null : round((float) $values[$figure], 2);
        $count = fn (string $figure): ?int => $values[$figure] === null ? null : (int) $values[$figure];
        $principal = $money('outstanding_principal') ?? 0.0;

        $figures = [
            'loan_count' => $count('loan_count'),
            'active_loans' => $count('active_loan_count'),
            'outstanding_principal' => $money('outstanding_principal'),
            'total_outstanding' => $money('total_outstanding'),
            'past_due_amount' => $money('overdue_amount'),
            'past_due_loan_count' => $count('overdue_loans_count'),
        ];

        foreach (array_keys(PortfolioRisk::PAR_DAYS) as $key) {
            $amount = $money("{$key}_amount");

            $figures[$key] = $amount === null ? null : [
                'amount' => $amount,
                'ratio' => $principal > 0 ? round($amount / $principal * 100, 2) : 0.0,
                'loans' => $count("{$key}_loans"),
            ];
        }

        return $figures;
    }
}
