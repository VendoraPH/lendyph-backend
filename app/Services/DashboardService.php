<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\Repayment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * What a posted payment collected for the loan: everything it took less
     * the insurance premium it collected first. That premium is held for the
     * insurer (2040 Insurance Premium Payable), not loan collection, so it is
     * stated separately as `insurance_collected`, as Daily Collection and
     * Collection Efficiency already leave it out.
     */
    private const COLLECTED_SQL = 'COALESCE(SUM(amount_paid - insurance_applied), 0)';

    private const INSURANCE_COLLECTED_SQL = 'COALESCE(SUM(insurance_applied), 0)';

    public function stats(): array
    {
        $monthStart = Carbon::now()->startOfMonth();

        $totalPortfolio = (float) Loan::whereIn('status', ['released', 'ongoing', 'completed'])
            ->sum('principal_amount');

        $activeLoansCount = Loan::whereIn('status', ['released', 'ongoing'])->count();

        $collectedMtd = Repayment::where('status', 'posted')
            ->whereDate('payment_date', '>=', $monthStart)
            ->selectRaw(self::COLLECTED_SQL.' as collected, '.self::INSURANCE_COLLECTED_SQL.' as insurance')
            ->first();
        $totalCollectedMtd = (float) $collectedMtd->collected;
        $insuranceCollectedMtd = (float) $collectedMtd->insurance;

        $overdueLoansCount = $this->overdueLoansCount(Carbon::today());

        // Cache sparklines for 10 minutes (28 queries → 0 on cache hit)
        $sparklines = Cache::remember('dashboard:sparklines', 600, fn () => [
            'portfolio' => $this->portfolioSparkline(),
            'active_loans' => $this->activeLoansSparkline(),
            'collected' => $this->collectionsSparkline(),
            'overdue' => $this->overdueSparkline(),
        ]);

        return [
            'total_portfolio' => round($totalPortfolio, 2),
            'active_loans' => $activeLoansCount,
            'total_collected' => round($totalCollectedMtd, 2),
            'insurance_collected' => round($insuranceCollectedMtd, 2),
            'overdue_count' => $overdueLoansCount,
            // Legacy aliases kept for backward compatibility — prefer the names above.
            'active_loans_count' => $activeLoansCount,
            'total_collected_mtd' => round($totalCollectedMtd, 2),
            'overdue_loans_count' => $overdueLoansCount,
            'sparklines' => $sparklines,
        ];
    }

    public function collectionsTrend(string $period = 'week'): array
    {
        return match ($period) {
            'month' => $this->monthlyTrend(),
            'year' => $this->yearlyTrend(),
            default => $this->weeklyTrend(),
        };
    }

    private function weeklyTrend(): array
    {
        $points = collect();
        for ($i = 11; $i >= 0; $i--) {
            $start = Carbon::now()->startOfWeek()->subWeeks($i);
            $end = $start->copy()->endOfWeek();

            [$total, $insurance] = $this->collectedBetween($start, $end);

            $points->push([
                'period' => 'week',
                'key' => 'W'.(12 - $i),
                'label' => $start->format('M j'),
                'period_label' => $start->format('M j'),
                'value' => round($total, 2),
                // Legacy alias.
                'total' => round($total, 2),
                'insurance_collected' => round($insurance, 2),
            ]);
        }

        return ['data' => $points->values()->toArray()];
    }

    private function monthlyTrend(): array
    {
        $points = collect();
        for ($i = 11; $i >= 0; $i--) {
            $start = Carbon::now()->startOfMonth()->subMonths($i);
            $end = $start->copy()->endOfMonth();

            [$total, $insurance] = $this->collectedBetween($start, $end);

            $points->push([
                'period' => 'month',
                'key' => $start->format('Y-m'),
                'label' => $start->format('M Y'),
                'period_label' => $start->format('M Y'),
                'value' => round($total, 2),
                'total' => round($total, 2),
                'insurance_collected' => round($insurance, 2),
            ]);
        }

        return ['data' => $points->values()->toArray()];
    }

    private function yearlyTrend(): array
    {
        $points = collect();
        for ($i = 4; $i >= 0; $i--) {
            $start = Carbon::now()->startOfYear()->subYears($i);
            $end = $start->copy()->endOfYear();

            [$total, $insurance] = $this->collectedBetween($start, $end);

            $points->push([
                'period' => 'year',
                'key' => (string) $start->year,
                'label' => (string) $start->year,
                'period_label' => (string) $start->year,
                'value' => round($total, 2),
                'total' => round($total, 2),
                'insurance_collected' => round($insurance, 2),
            ]);
        }

        return ['data' => $points->values()->toArray()];
    }

    /**
     * Collected (premium left out) and the premium collected, by posted
     * payments dated in the range.
     *
     * @return array{0: float, 1: float}
     */
    private function collectedBetween(Carbon $start, Carbon $end): array
    {
        $row = Repayment::where('status', 'posted')
            ->whereBetween('payment_date', [$start, $end])
            ->selectRaw(self::COLLECTED_SQL.' as collected, '.self::INSURANCE_COLLECTED_SQL.' as insurance')
            ->first();

        return [(float) $row->collected, (float) $row->insurance];
    }

    public function dailyDues(?string $date = null): array
    {
        $targetDate = $date ? Carbon::parse($date) : Carbon::today();

        $schedules = DB::table('amortization_schedules')
            ->join('loans', 'amortization_schedules.loan_id', '=', 'loans.id')
            ->join('borrowers', 'loans.borrower_id', '=', 'borrowers.id')
            ->whereDate('amortization_schedules.due_date', $targetDate)
            ->whereIn('loans.status', ['released', 'ongoing'])
            // A period with nothing due (closed by an adjustment) is not a due.
            ->where('amortization_schedules.total_due', '>', 0)
            ->select(
                'amortization_schedules.id',
                'amortization_schedules.loan_id',
                'amortization_schedules.total_due',
                'amortization_schedules.principal_paid',
                'amortization_schedules.interest_paid',
                'amortization_schedules.penalty_paid',
                'loans.loan_account_number',
                DB::raw("CONCAT(borrowers.first_name, ' ', borrowers.last_name) as borrower_name")
            )
            ->orderBy('amortization_schedules.id')
            ->get();

        // One item per loan for the date: a period an extension or
        // restructure closed early (at what it had collected) shares its due
        // date with the rescheduled remainder, and both are one due.
        $items = $schedules->groupBy('loan_id')->map(fn ($rows): array => $this->dailyDueItem($rows));

        $centavos = fn (float $pesos): int => (int) round($pesos * 100);
        $totalDue = $items->sum(fn (array $item): int => $centavos($item['amount_due']));
        $collectedInFull = $items->where('status', 'collected')->sum(fn (array $item): int => $centavos($item['amount_due']));
        $partiallyCollected = $items->where('status', 'partial')->sum(fn (array $item): int => $centavos($item['amount_collected']));
        $totalCollected = $collectedInFull + $partiallyCollected;

        return [
            'data' => $items->values()->toArray(),
            'summary' => [
                'total_due' => $totalDue / 100,
                'total_collected' => $totalCollected / 100,
                'collected_in_full' => $collectedInFull / 100,
                'partially_collected' => $partiallyCollected / 100,
                'collection_rate' => $totalDue > 0 ? round($totalCollected / $totalDue * 100, 1) : 0,
                'uncollected' => max(0, $totalDue - $totalCollected) / 100,
                'counts' => [
                    'collected' => $items->where('status', 'collected')->count(),
                    'partial' => $items->where('status', 'partial')->count(),
                    'pending' => $items->where('status', 'pending')->count(),
                ],
            ],
        ];
    }

    /**
     * One daily-dues item for one loan, by the principal and interest paid
     * against its periods due that day: `collected` once they cover the
     * amount due, `partial` while some but not all of it is paid, `pending`
     * while none is. Penalty paid never counts towards the amount due: a
     * period with only its penalty paid (₱0 principal and interest) is
     * `pending`, one with penalty and part of the principal and interest paid
     * is `partial`, and the collection rate can never pass 100%.
     *
     * A loan usually has one period due on a date. When an extension or
     * restructure closed a period early, at what it had collected, and its
     * rescheduled remainder falls due on the same date, the periods are
     * folded into one item: `amount_due` and `amount_collected` both include
     * the closed part, so the item is `collected` only once the remainder's
     * principal and interest are paid too. The fold reads no closing marker:
     * every period of the loan due that day is part of the one due.
     *
     * `amount_collected` is the principal and interest paid towards the amount
     * due, capped per period at what that period owes. `amount_paid` stays
     * everything paid, penalty included.
     *
     * @param  Collection<int, object>  $periods  the loan's periods due that day
     * @return array{loan_id: int, loan_account_number: string|null, borrower_name: string, amount_due: float, amount_paid: float, amount_collected: float, status: string}
     */
    private function dailyDueItem(Collection $periods): array
    {
        $centavos = fn (mixed $pesos): int => (int) round((float) $pesos * 100);
        $amountDue = 0;
        $amountPaid = 0;
        $collected = 0;

        foreach ($periods as $period) {
            $due = $centavos($period->total_due);
            $principalAndInterest = $centavos($period->principal_paid) + $centavos($period->interest_paid);

            $amountDue += $due;
            $amountPaid += $principalAndInterest + $centavos($period->penalty_paid);
            $collected += min($principalAndInterest, $due);
        }

        $status = match (true) {
            $collected >= $amountDue => 'collected',
            $collected > 0 => 'partial',
            default => 'pending',
        };

        $first = $periods->first();

        return [
            'loan_id' => $first->loan_id,
            'loan_account_number' => $first->loan_account_number,
            'borrower_name' => $first->borrower_name,
            'amount_due' => $amountDue / 100,
            'amount_paid' => $amountPaid / 100,
            'amount_collected' => $collected / 100,
            'status' => $status,
        ];
    }

    public function recentTransactions(int $limit = 10): array
    {
        $payments = Repayment::with('loan.borrower')
            ->where('status', 'posted')
            ->latest('payment_date')
            ->take($limit)
            ->get()
            ->map(fn ($r) => [
                'type' => 'payment',
                'borrower_name' => $r->loan?->borrower?->full_name,
                'description' => 'Payment via '.ucwords(str_replace('_', ' ', $r->method)),
                'amount' => (float) $r->amount_paid,
                'date' => $r->payment_date?->format('M j, g:i A'),
                'created_at' => $r->created_at,
            ]);

        $releases = Loan::with('borrower')
            ->whereNotNull('released_at')
            ->latest('released_at')
            ->take($limit)
            ->get()
            ->map(fn ($l) => [
                'type' => 'release',
                'borrower_name' => $l->borrower?->full_name,
                'description' => 'Loan released',
                'amount' => (float) $l->net_proceeds,
                'date' => $l->released_at?->format('M j, g:i A'),
                'created_at' => $l->released_at,
            ]);

        $merged = $payments->concat($releases)
            ->sortByDesc('created_at')
            ->take($limit)
            ->values()
            ->map(fn ($item) => collect($item)->except('created_at')->toArray());

        return ['data' => $merged->toArray()];
    }

    private function portfolioSparkline(): array
    {
        return collect(range(6, 0))->map(function ($weeksAgo) {
            $end = Carbon::now()->startOfWeek()->subWeeks($weeksAgo)->endOfWeek();
            $value = (float) Loan::whereIn('status', ['released', 'ongoing', 'completed'])
                ->where('released_at', '<=', $end)
                ->sum('principal_amount');

            return ['v' => round($value / 1000, 0)];
        })->toArray();
    }

    private function activeLoansSparkline(): array
    {
        return collect(range(6, 0))->map(function ($weeksAgo) {
            $end = Carbon::now()->startOfWeek()->subWeeks($weeksAgo)->endOfWeek();
            $value = Loan::where('status', 'released')
                ->where('released_at', '<=', $end)
                ->count();

            return ['v' => $value];
        })->toArray();
    }

    private function collectionsSparkline(): array
    {
        return collect(range(6, 0))->map(function ($daysAgo) {
            $day = Carbon::today()->subDays($daysAgo);
            $value = (float) Repayment::where('status', 'posted')
                ->whereDate('payment_date', $day)
                ->selectRaw(self::COLLECTED_SQL.' as collected')
                ->value('collected');

            return ['v' => round($value / 1000, 0)];
        })->toArray();
    }

    /**
     * Each of the last seven days by the same rule as the card, with that day
     * as the grace cutoff's date, so today's point is the card's figure.
     * Payment state is today's: a period paid since that day no longer counts
     * on it, as before.
     */
    private function overdueSparkline(): array
    {
        return collect(range(6, 0))->map(fn ($daysAgo) => [
            'v' => $this->overdueLoansCount(Carbon::today()->subDays($daysAgo)),
        ])->toArray();
    }

    /**
     * Collectible loans with an unpaid period past the due date plus the
     * loan's grace, as of `$asOf`: LoanListTotals' `overdue_count`, the rule
     * GET /loans `meta.totals.overdue_count` counts by. A period's stored
     * `overdue` status is not read, as it is only stamped when a payment or
     * the penalty run touches the loan.
     */
    private function overdueLoansCount(Carbon $asOf): int
    {
        return app(LoanListTotals::class)->for(
            Loan::query()->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES),
            $asOf,
        )['overdue_count'];
    }
}
