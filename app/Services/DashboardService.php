<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Loan;
use App\Models\PortfolioDailySnapshot;
use App\Models\Repayment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The statuses the Active Loans card and its sparkline count.
     */
    private const ACTIVE_STATUSES = ['released', 'ongoing'];

    /**
     * Every figure below takes `$branchIds` (decision B2, least privilege):
     * the caller's branches as BranchAccess::for() answers them, null for
     * `branches:view_all` (the whole organisation). Loans count by their
     * branch, payments by their loan's branch. An empty list matches nothing.
     * The scope has no default, so no caller can forget it and read the
     * whole organisation by accident.
     *
     * @param  list<int>|null  $branchIds
     */
    public function stats(?array $branchIds): array
    {
        $monthStart = Carbon::now()->startOfMonth();

        // One read of the collectible loans. The gross loan portfolio is the
        // Portfolio Summary's `outstanding_principal`, for the same branches;
        // the overdue count is GET /loans `meta.totals.overdue_count`'s rule.
        $collectible = app(PortfolioOutstanding::class)->totals($branchIds);
        $totalPortfolio = $collectible['outstanding_principal'];

        $activeLoansCount = $this->loans($branchIds)->whereIn('status', self::ACTIVE_STATUSES)->count();

        $collectedMtd = $this->repayments($branchIds)
            ->where('status', 'posted')
            ->whereDate('payment_date', '>=', $monthStart)
            ->selectRaw(self::COLLECTED_SQL.' as collected, '.self::INSURANCE_COLLECTED_SQL.' as insurance')
            ->first();
        $totalCollectedMtd = (float) $collectedMtd->collected;
        $insuranceCollectedMtd = (float) $collectedMtd->insurance;

        $overdueLoansCount = $collectible['overdue_count'];

        // Cache sparklines for 10 minutes (22 queries → 0 on cache hit). v5:
        // the portfolio points read the unpaid principal, and the points are per
        // branch scope, so points cached under the old key's rule are not
        // served after a deploy. The day is in the key so points
        // cached before midnight are not served as the next day's; the scope
        // is in it so one branch's points are never served to another.
        $cacheKey = 'dashboard:sparklines:v5:'.Carbon::today()->toDateString().':'.self::scopeKey($branchIds);
        $sparklines = Cache::remember($cacheKey, 600, fn () => [
            'portfolio' => $this->pastPortfolioSparkline($branchIds),
            'active_loans' => $this->activeLoansSparkline($branchIds),
            'collected' => $this->collectionsSparkline($branchIds),
            'overdue' => $this->pastOverdueSparkline($branchIds),
        ]);

        // Today's point is the card's live figure, never a cached one, so the
        // two always agree.
        $sparklines['overdue'][] = ['v' => $overdueLoansCount];
        $sparklines['portfolio'][] = ['v' => round($totalPortfolio / 1000, 0)];

        return [
            'total_portfolio' => $totalPortfolio,
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

    /**
     * @param  list<int>|null  $branchIds
     */
    public function collectionsTrend(?array $branchIds, string $period = 'week'): array
    {
        return match ($period) {
            'month' => $this->monthlyTrend($branchIds),
            'year' => $this->yearlyTrend($branchIds),
            default => $this->weeklyTrend($branchIds),
        };
    }

    /**
     * @param  list<int>|null  $branchIds
     */
    private function weeklyTrend(?array $branchIds): array
    {
        $points = collect();
        for ($i = 11; $i >= 0; $i--) {
            $start = Carbon::now()->startOfWeek()->subWeeks($i);
            $end = $start->copy()->endOfWeek();

            [$total, $insurance] = $this->collectedBetween($start, $end, $branchIds);

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

    /**
     * @param  list<int>|null  $branchIds
     */
    private function monthlyTrend(?array $branchIds): array
    {
        $points = collect();
        for ($i = 11; $i >= 0; $i--) {
            $start = Carbon::now()->startOfMonth()->subMonths($i);
            $end = $start->copy()->endOfMonth();

            [$total, $insurance] = $this->collectedBetween($start, $end, $branchIds);

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

    /**
     * @param  list<int>|null  $branchIds
     */
    private function yearlyTrend(?array $branchIds): array
    {
        $points = collect();
        for ($i = 4; $i >= 0; $i--) {
            $start = Carbon::now()->startOfYear()->subYears($i);
            $end = $start->copy()->endOfYear();

            [$total, $insurance] = $this->collectedBetween($start, $end, $branchIds);

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
     * @param  list<int>|null  $branchIds
     * @return array{0: float, 1: float}
     */
    private function collectedBetween(Carbon $start, Carbon $end, ?array $branchIds): array
    {
        $row = $this->repayments($branchIds)
            ->where('status', 'posted')
            ->whereBetween('payment_date', [$start, $end])
            ->selectRaw(self::COLLECTED_SQL.' as collected, '.self::INSURANCE_COLLECTED_SQL.' as insurance')
            ->first();

        return [(float) $row->collected, (float) $row->insurance];
    }

    /**
     * @param  list<int>|null  $branchIds
     */
    public function dailyDues(?array $branchIds, ?string $date = null): array
    {
        $targetDate = $date ? Carbon::parse($date) : Carbon::today();

        $schedules = DB::table('amortization_schedules')
            ->join('loans', 'amortization_schedules.loan_id', '=', 'loans.id')
            ->join('borrowers', 'loans.borrower_id', '=', 'borrowers.id')
            ->whereDate('amortization_schedules.due_date', $targetDate)
            ->whereIn('loans.status', ['released', 'ongoing'])
            ->when($branchIds !== null, fn ($q) => $q->whereIn('loans.branch_id', $branchIds))
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

    /**
     * The latest payments and releases, newest first, in words.
     *
     * A payment reads "Payment via <method>" with the method as the statement
     * prints it (PaymentMethod::labelFor()). A release pays out its net
     * proceeds. A restructure's release pays nothing out: it collects every
     * deduction it withholds from the member (`total_deductions`, the amount
     * its release journal collects), so it reads "Loan restructured" with
     * that amount and its own type.
     *
     * Newest first across both sources, by the date each row shows: a
     * payment's payment date, a release's release time. A payment recorded
     * today for an earlier day sits at that day. Rows on the same day go by
     * when they happened (a payment's recording time, a release's time), then
     * by id, so the order never depends on how the database returns ties.
     * Each source is read in that same order, so its first `$limit` rows are
     * the ones the merge can need.
     *
     * @param  list<int>|null  $branchIds
     */
    public function recentTransactions(?array $branchIds, int $limit = 10): array
    {
        $payments = $this->repayments($branchIds)
            ->with('loan.borrower')
            ->where('status', 'posted')
            ->orderByDesc('payment_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->take($limit)
            ->get()
            ->map(fn ($r) => [
                'type' => 'payment',
                'borrower_name' => $r->loan?->borrower?->full_name,
                'description' => 'Payment via '.PaymentMethod::labelFor($r->method),
                'amount' => (float) $r->amount_paid,
                'date' => $r->payment_date?->format('M j, g:i A'),
                'sort' => [$r->payment_date?->toDateString(), $r->created_at?->format('Y-m-d H:i:s'), $r->id],
            ]);

        $releases = $this->loans($branchIds)
            ->with('borrower')
            ->whereNotNull('released_at')
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->take($limit)
            ->get()
            ->map(fn ($l) => [
                'type' => $l->isRestructure() ? 'restructure' : 'release',
                'borrower_name' => $l->borrower?->full_name,
                'description' => $l->isRestructure() ? 'Loan restructured' : 'Loan released',
                'amount' => (float) ($l->isRestructure() ? $l->total_deductions : $l->net_proceeds),
                'date' => $l->released_at?->format('M j, g:i A'),
                'sort' => [$l->released_at?->toDateString(), $l->released_at?->format('Y-m-d H:i:s'), $l->id],
            ]);

        $merged = $payments->concat($releases)
            ->sort(fn (array $a, array $b): int => [...$b['sort'], $b['type']] <=> [...$a['sort'], $a['type']])
            ->take($limit)
            ->values()
            ->map(fn ($item) => collect($item)->except('sort')->toArray());

        return ['data' => $merged->toArray()];
    }

    /**
     * The Total Portfolio sparkline's six past weeks, in thousands: the
     * outstanding principal in the last daily snapshot of each week, oldest
     * first, by the card's rule (collectible loans, LoanListTotals). A week with
     * no snapshot has no point, nothing being recalculated for a day that
     * passed. This week's point is the card's live figure, added
     * by stats(). As with the overdue sparkline, snapshots are the whole
     * organisation's, so a branch user gets no past points (decision B2).
     *
     * @param  list<int>|null  $branchIds
     * @return array<int, array{v: float|null}>
     */
    private function pastPortfolioSparkline(?array $branchIds): array
    {
        if ($branchIds !== null) {
            return array_fill(0, 6, ['v' => null]);
        }

        $thisWeek = Carbon::now()->startOfWeek();

        $snapshots = PortfolioDailySnapshot::query()
            ->whereBetween('snapshot_date', [$thisWeek->copy()->subWeeks(6)->toDateString(), $thisWeek->copy()->subDay()->toDateString()])
            ->orderBy('snapshot_date')
            ->get(['snapshot_date', 'outstanding_principal']);

        return collect(range(6, 1))->map(function (int $weeksAgo) use ($snapshots, $thisWeek): array {
            $start = $thisWeek->copy()->subWeeks($weeksAgo);
            $end = $start->copy()->endOfWeek();
            $last = $snapshots->last(fn (PortfolioDailySnapshot $s): bool => $s->snapshot_date->betweenIncluded($start, $end));

            return ['v' => $last === null ? null : round((float) $last->outstanding_principal / 1000, 0)];
        })->values()->all();
    }

    /**
     * Active loans (released and ongoing, the card's statuses) released by
     * the end of each of the last seven weeks; this week's point equals the
     * card. It used to count `released` only, so it read low beside the card
     * as soon as a loan took its first payment and became `ongoing`.
     *
     * @param  list<int>|null  $branchIds
     */
    private function activeLoansSparkline(?array $branchIds): array
    {
        return collect(range(6, 0))->map(function ($weeksAgo) use ($branchIds) {
            $end = Carbon::now()->startOfWeek()->subWeeks($weeksAgo)->endOfWeek();
            $value = $this->loans($branchIds)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->where('released_at', '<=', $end)
                ->count();

            return ['v' => $value];
        })->toArray();
    }

    /**
     * @param  list<int>|null  $branchIds
     */
    private function collectionsSparkline(?array $branchIds): array
    {
        return collect(range(6, 0))->map(function ($daysAgo) use ($branchIds) {
            $day = Carbon::today()->subDays($daysAgo);
            $value = (float) $this->repayments($branchIds)
                ->where('status', 'posted')
                ->whereDate('payment_date', $day)
                ->selectRaw(self::COLLECTED_SQL.' as collected')
                ->value('collected');

            return ['v' => round($value / 1000, 0)];
        })->toArray();
    }

    /**
     * The six days before today from the daily portfolio snapshots
     * (`portfolio:snapshot`, decision 2d): each point is that day's
     * `overdue_loans_count`, or null when no snapshot was recorded for it (no
     * backfill). stats() adds today's point from the live card.
     *
     * The snapshots are organisation-wide (they record no branch), so a
     * caller without `branches:view_all` gets null for every past point and
     * only their own live today point (decision B2): a branch user never
     * reads another branch's count through the sparkline.
     *
     * @param  list<int>|null  $branchIds
     * @return array<int, array{v: int|null}>
     */
    private function pastOverdueSparkline(?array $branchIds): array
    {
        $today = Carbon::today();

        if ($branchIds !== null) {
            return array_fill(0, 6, ['v' => null]);
        }

        $counts = PortfolioDailySnapshot::query()
            ->whereBetween('snapshot_date', [$today->copy()->subDays(6)->toDateString(), $today->copy()->subDay()->toDateString()])
            ->get(['snapshot_date', 'overdue_loans_count'])
            ->mapWithKeys(fn (PortfolioDailySnapshot $snapshot) => [$snapshot->snapshot_date->toDateString() => $snapshot->overdue_loans_count]);

        return collect(range(6, 1))->map(fn (int $daysAgo) => [
            'v' => $counts[$today->copy()->subDays($daysAgo)->toDateString()] ?? null,
        ])->values()->all();
    }

    /**
     * Loans in $branchIds (every loan when null).
     *
     * @param  list<int>|null  $branchIds
     * @return Builder<Loan>
     */
    private function loans(?array $branchIds): Builder
    {
        return Loan::query()->when($branchIds !== null, fn ($q) => $q->whereIn('loans.branch_id', $branchIds));
    }

    /**
     * Repayments on loans in $branchIds (every repayment when null).
     *
     * @param  list<int>|null  $branchIds
     * @return Builder<Repayment>
     */
    private function repayments(?array $branchIds): Builder
    {
        return Repayment::query()->when($branchIds !== null, fn ($q) => $q->whereIn(
            'repayments.loan_id',
            Loan::query()->whereIn('loans.branch_id', $branchIds)->select('loans.id'),
        ));
    }

    /**
     * The sparkline cache key's scope part: `all`, or a hash of the sorted
     * branch ids, so a user in many branches never builds an unbounded key.
     *
     * @param  list<int>|null  $branchIds
     */
    private static function scopeKey(?array $branchIds): string
    {
        if ($branchIds === null) {
            return 'all';
        }

        sort($branchIds);

        return 'branches-'.sha1(implode(',', $branchIds));
    }
}
