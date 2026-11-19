<?php

namespace App\Services\Dashboard;

use App\Models\Loan;
use App\Models\PortfolioSnapshotLine;
use App\Services\LoanListTotals;
use App\Services\PortfolioRisk;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * The daily portfolio snapshot's figures (Phase 6 EB-B), for the whole
 * organisation and per branch × loan product, worked out by the services the
 * reports use, so a day's lines add up to its organisation row and the row
 * equals the Portfolio Summary:
 *
 * - over the collectible loans (released, ongoing, defaulted);
 * - `loan_count` and `active_loan_count` (released and ongoing, the Active
 *   Loans card's statuses);
 * - `overdue_loans_count`, `overdue_amount`, `total_outstanding` and
 *   `outstanding_principal`: LoanListTotals, overdue by the grace rule;
 * - PAR 1/30/60/90 amounts and loan counts: PortfolioRisk, from the bare
 *   due date.
 *
 * Reads only; `portfolio:snapshot` writes. `$asOf` is the day recorded. A
 * past day is worked out with today's payment state (the command warns).
 */
class PortfolioSnapshotLines
{
    private const GROUP = ['loans.branch_id', 'loans.loan_product_id'];

    public function __construct(
        private LoanListTotals $listTotals,
        private PortfolioRisk $risk,
    ) {}

    /**
     * The organisation-wide figures for the day.
     *
     * @return array<string, float|int>
     */
    public function organisation(Carbon $asOf): array
    {
        $totals = $this->listTotals->for($this->collectible(), $asOf);
        $par = $this->risk->par($this->collectible(), $asOf);

        return $this->figures(
            $totals + ['loan_count' => $this->collectible()->count()],
            $par,
            $this->collectible()->whereIn('loans.status', Loan::ACTIVE_STATUSES)->count(),
        );
    }

    /**
     * One line per branch × loan product with a collectible loan, ordered by
     * branch then product, each with `branch_id`, `loan_product_id` and
     * PortfolioSnapshotLine::FIGURES.
     *
     * @return list<array<string, float|int>>
     */
    public function build(Carbon $asOf): array
    {
        $totals = $this->listTotals->grouped($this->collectible(), self::GROUP, $asOf);
        $par = $this->risk->parBy($this->collectible(), self::GROUP, $asOf);

        $active = $this->collectible()
            ->whereIn('loans.status', Loan::ACTIVE_STATUSES)
            ->toBase()
            ->groupBy(self::GROUP)
            ->select(self::GROUP)
            ->selectRaw('COUNT(*) as active_loan_count')
            ->get()
            ->mapWithKeys(fn (object $row) => [LoanListTotals::groupKey($row, self::GROUP) => (int) $row->active_loan_count]);

        $lines = [];
        foreach ($totals as $key => $groupTotals) {
            [$branchId, $productId] = array_map('intval', explode('-', $key));

            $lines[] = [
                'branch_id' => $branchId,
                'loan_product_id' => $productId,
                ...$this->figures($groupTotals, $par[$key] ?? null, $active[$key] ?? 0),
            ];
        }

        usort($lines, fn (array $a, array $b): int => [$a['branch_id'], $a['loan_product_id']] <=> [$b['branch_id'], $b['loan_product_id']]);

        return $lines;
    }

    /**
     * Each figure where the lines and the organisation differ, by more than
     * nothing (money compared in whole centavos): `{organisation, lines}`.
     * Empty when the lines add up.
     *
     * @param  array<string, float|int>  $organisation
     * @param  list<array<string, float|int>>  $lines
     * @return array<string, array{organisation: float|int, lines: float|int}>
     */
    public function differences(array $organisation, array $lines): array
    {
        $differences = [];

        foreach (PortfolioSnapshotLine::FIGURES as $figure) {
            $isMoney = in_array($figure, PortfolioSnapshotLine::MONEY_FIGURES, true);
            $unit = fn (float|int $value): int => $isMoney ? (int) round($value * 100) : (int) $value;

            $linesTotal = array_sum(array_map(fn (array $line): int => $unit($line[$figure]), $lines));

            if ($linesTotal !== $unit($organisation[$figure])) {
                $differences[$figure] = [
                    'organisation' => $organisation[$figure],
                    'lines' => $isMoney ? $linesTotal / 100 : $linesTotal,
                ];
            }
        }

        return $differences;
    }

    /**
     * @param  array{total_outstanding: float, outstanding_principal: float, overdue_amount: float, overdue_count: int, loan_count: int}  $totals
     * @param  array<string, mixed>|null  $par  PortfolioRisk::par()
     * @return array<string, float|int>
     */
    private function figures(array $totals, ?array $par, int $active): array
    {
        $figures = [
            'loan_count' => $totals['loan_count'],
            'active_loan_count' => $active,
            'overdue_loans_count' => $totals['overdue_count'],
            'overdue_amount' => $totals['overdue_amount'],
            'total_outstanding' => $totals['total_outstanding'],
            'outstanding_principal' => $totals['outstanding_principal'],
        ];

        foreach (array_keys(PortfolioRisk::PAR_DAYS) as $key) {
            $figures["{$key}_amount"] = $par[$key]['amount'] ?? 0.0;
            $figures["{$key}_loans"] = $par[$key]['loans'] ?? 0;
        }

        return $figures;
    }

    /**
     * @return Builder<Loan>
     */
    private function collectible(): Builder
    {
        return Loan::query()->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES);
    }
}
