<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Loan;
use App\Models\PortfolioDailySnapshot;
use App\Models\PortfolioSnapshotLine;
use App\Models\Repayment;
use Carbon\Carbon;
use Closure;
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

        // Cache sparklines for 10 minutes. v6: past points come from the
        // snapshots for every caller (a branch user's from their branches'
        // lines) and the Active Loans points too, so points cached under the
        // old key's rule are not served after a deploy. The day is in the key
        // so points cached before midnight are not served as the next day's;
        // the scope is in it so one branch's points are never served to
        // another.
        $cacheKey = 'dashboard:sparklines:v6:'.Carbon::today()->toDateString().':'.self::scopeKey($branchIds);
        $sparklines = Cache::remember($cacheKey, 600, fn () => [
            'portfolio' => $this->pastWeeklySparkline($branchIds, 'outstanding_principal', fn (float $v): float => round($v / 1000, 0)),
            'active_loans' => $this->pastWeeklySparkline($branchIds, 'active_loan_count', fn (float $v): int => (int) $v),
            'collected' => $this->collectionsSparkline($branchIds),
            'overdue' => $this->pastOverdueSparkline($branchIds),
        ]);

        // Today's point is the card's live figure, never a cached one, so the
        // two always agree.
        $sparklines['overdue'][] = ['v' => $overdueLoansCount];
        $sparklines['portfolio'][] = ['v' => round($totalPortfolio / 1000, 0)];
        $sparklines['active_loans'][] = ['v' => $activeLoansCount];

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
                'loans.external_loan_no',
                'loans.application_number',
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
            'display_number' => Loan::displayNumberOf($first->loan_account_number, $first->external_loan_no, $first->application_number),
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
     * A payment row's `date` is paymentRowDate(); a release row's is its
     * release time.
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
                'date' => self::paymentRowDate($r),
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
     * A payment row's date in words. `payment_date` holds no time, so it shows
     * the time the payment was recorded ("Nov 10, 2:05 PM") only when that was
     * on the payment date itself, in Manila (timestamps are stored in Manila
     * time). A payment recorded on another day, or with no recorded time,
     * shows its payment date alone ("Nov 9"), never a made-up "12:00 AM".
     */
    private static function paymentRowDate(Repayment $repayment): ?string
    {
        $paidOn = $repayment->payment_date;

        if ($paidOn === null) {
            return null;
        }

        $recordedAt = $repayment->created_at?->copy()->setTimezone('Asia/Manila');

        return $recordedAt !== null && $recordedAt->toDateString() === $paidOn->toDateString()
            ? $recordedAt->format('M j, g:i A')
            : $paidOn->format('M j');
    }

    /**
     * The Total Portfolio and Active Loans sparklines' six past weeks: the
     * figure in the last daily snapshot of each week, oldest first, through
     * `$format`. A week with no snapshot has no point, nothing being
     * recalculated for a day that passed. This week's point is the card's live
     * figure, added by stats().
     *
     * `outstanding_principal` is the card's rule (collectible loans,
     * LoanListTotals); `active_loan_count` is released and ongoing loans as
     * they stood that day, so a loan completed since still counts in the week
     * it was active (Phase 6 EB-B; it used to count today's loans backwards).
     *
     * @param  list<int>|null  $branchIds
     * @param  Closure(float): (float|int)  $format
     * @return array<int, array{v: float|int|null}>
     */
    private function pastWeeklySparkline(?array $branchIds, string $figure, Closure $format): array
    {
        $thisWeek = Carbon::now()->startOfWeek();
        $values = $this->snapshotValues($branchIds, $figure, $thisWeek->copy()->subWeeks(6), $thisWeek->copy()->subDay());

        return collect(range(6, 1))->map(function (int $weeksAgo) use ($values, $thisWeek, $format): array {
            $start = $thisWeek->copy()->subWeeks($weeksAgo)->toDateString();
            $end = $thisWeek->copy()->subWeeks($weeksAgo)->endOfWeek()->toDateString();
            $last = $values->filter(fn ($value, string $day): bool => $day >= $start && $day <= $end)->last();

            return ['v' => $last === null ? null : $format((float) $last)];
        })->values()->all();
    }

    /**
     * One figure per snapshot day from `$from` to `$to`, by day (Y-m-d),
     * oldest first; a day with no usable snapshot has no key (no backfill).
     *
     * - The whole organisation (`$branchIds` null) reads each day's own row;
     *   a figure the row never held (recorded before Phase 6 EB-B) has no key.
     * - A branch user reads their branches' snapshot lines, on the days that
     *   have lines (`line_count` set), added up; a day with lines but none in
     *   their branches reads 0. They never read another branch's figure, and
     *   the organisation row is never theirs.
     *
     * @param  list<int>|null  $branchIds
     * @return Collection<string, float|int>
     */
    private function snapshotValues(?array $branchIds, string $figure, Carbon $from, Carbon $to): Collection
    {
        $days = PortfolioDailySnapshot::query()
            ->whereBetween('snapshot_date', [$from->toDateString(), $to->toDateString()])
            ->when($branchIds !== null, fn ($q) => $q->whereNotNull('line_count'), fn ($q) => $q->whereNotNull($figure))
            ->orderBy('snapshot_date')
            ->get(['snapshot_date', $figure]);

        if ($branchIds === null) {
            return $days->mapWithKeys(fn (PortfolioDailySnapshot $day) => [$day->snapshot_date->toDateString() => $day->{$figure}]);
        }

        $sums = PortfolioSnapshotLine::query()
            ->toBase()
            ->whereIn('snapshot_date', $days->map(fn (PortfolioDailySnapshot $day) => $day->snapshot_date->toDateString())->all())
            ->whereIn('branch_id', $branchIds)
            ->groupBy('snapshot_date')
            ->selectRaw("snapshot_date, COALESCE(SUM({$figure}), 0) as figure")
            ->pluck('figure', 'snapshot_date')
            ->mapWithKeys(fn ($value, $day) => [Carbon::parse($day)->toDateString() => $value]);

        return $days->mapWithKeys(function (PortfolioDailySnapshot $day) use ($sums): array {
            $date = $day->snapshot_date->toDateString();

            return [$date => $sums[$date] ?? 0];
        });
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
     * A caller without `branches:view_all` reads their own branches' snapshot
     * lines (Phase 6 EB-B), never another branch's count: a day recorded
     * before the lines has no point for them (self::snapshotValues()).
     *
     * @param  list<int>|null  $branchIds
     * @return array<int, array{v: int|null}>
     */
    private function pastOverdueSparkline(?array $branchIds): array
    {
        $today = Carbon::today();
        $counts = $this->snapshotValues($branchIds, 'overdue_loans_count', $today->copy()->subDays(6), $today->copy()->subDay());

        return collect(range(6, 1))->map(function (int $daysAgo) use ($counts, $today): array {
            $count = $counts[$today->copy()->subDays($daysAgo)->toDateString()] ?? null;

            return ['v' => $count === null ? null : (int) $count];
        })->values()->all();
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
