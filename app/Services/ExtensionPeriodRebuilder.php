<?php

namespace App\Services;

use App\Models\Loan;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Put back the partly paid periods that extensions deleted.
 *
 * Until 2026-10-02 an extension deleted the open period it replaced even when
 * money had been collected on it. That money dropped out of Total Paid, and
 * its payments could not be voided because their period was gone.
 *
 * This rebuilds such a period the way rescheduling now leaves one: closed at
 * what was collected on it, `paid`, and marked with the extension that
 * replaced it. It acts only where the result can be proven, and names every
 * other loan with the reason it was left alone:
 *
 *  - Only extensions rescheduled the loan. A restructure or term extension
 *    numbered its new periods over the ones it deleted, so there is no number
 *    left to put a rebuilt period back under.
 *  - No payment on it was voided, paid penalty, or already records its periods.
 *  - Its extensions form one chain: each started from the due date the one
 *    before set. Extension k then replaced the period extension k-1 created,
 *    so the numbers count back from the period the last extension created, or,
 *    when a restructure into a new loan has since cleared every period, on
 *    from the end of the loan's original schedule.
 *  - A payment belongs to the period open when it was made: after extension
 *    k-1 and no later than extension k (an extension that collects interest
 *    records that payment within the same second). The first extension's
 *    period takes whatever is missing beyond the later ones, because payments
 *    before it also paid periods that still exist.
 *  - The proof: replaying every posted payment over the periods in order, as
 *    the allocation backfill (2026_10_01_150200) does, reproduces each
 *    payment and each period's paid amounts to the centavo.
 *
 * It then writes the periods, every payment's allocation rows and one audit
 * row per loan, in one transaction per loan. A repaired loan reconciles, so a
 * second run finds nothing to do.
 */
class ExtensionPeriodRebuilder
{
    public const AUDIT_ACTION = 'periods_rebuilt';

    /** Component => [repayment total column, period paid column, period capacity column]. */
    private const COMPONENTS = [
        'penalty' => ['penalty_applied', 'penalty_paid', 'penalty_paid'],
        'interest' => ['interest_applied', 'interest_paid', 'interest_due'],
        'principal' => ['principal_applied', 'principal_paid', 'principal_due'],
    ];

    /** How far apart an extension and the period it created may be stamped. */
    private const SAME_WRITE_SECONDS = 5;

    /**
     * Rebuild every period that can be proven, or with `$dryRun` only say what
     * would be rebuilt.
     *
     * @return array{rebuilt: list<array{loan: string, periods: list<array<string, mixed>>}>, skipped: list<array{loan: string, reason: string}>}
     */
    public function run(bool $dryRun): array
    {
        $result = ['rebuilt' => [], 'skipped' => []];

        foreach ($this->loansMissingPayments() as $loan) {
            $label = $loan->loan_account_number ?? $loan->application_number;
            $plan = $this->plan($loan);

            if (is_string($plan)) {
                $result['skipped'][] = ['loan' => $label, 'reason' => $plan];

                continue;
            }

            if (! $dryRun) {
                DB::transaction(fn () => $this->write($loan, $plan));
            }

            $result['rebuilt'][] = ['loan' => $label, 'periods' => $this->describePeriods($plan)];
        }

        return $result;
    }

    /**
     * Loans whose posted payments add up to more than their periods hold.
     *
     * @return Collection<int, object>
     */
    private function loansMissingPayments(): Collection
    {
        $posted = DB::table('repayments')
            ->where('status', 'posted')
            ->groupBy('loan_id')
            ->selectRaw('loan_id, SUM(principal_applied) AS principal, SUM(interest_applied) AS interest, SUM(penalty_applied) AS penalty')
            ->get()
            ->keyBy('loan_id');

        $held = DB::table('amortization_schedules')
            ->whereIn('loan_id', $posted->keys())
            ->groupBy('loan_id')
            ->selectRaw('loan_id, SUM(principal_paid) AS principal, SUM(interest_paid) AS interest, SUM(penalty_paid) AS penalty')
            ->get()
            ->keyBy('loan_id');

        $loanIds = $posted->filter(fn (object $sums, int $loanId) => collect(['principal', 'interest', 'penalty'])
            ->contains(fn (string $c) => $this->centavos($sums->{$c}) !== $this->centavos($held[$loanId]->{$c} ?? 0)))
            ->keys();

        return DB::table('loans')->whereIn('id', $loanIds)->orderBy('id')->get();
    }

    /**
     * The periods to rebuild on one loan and every payment's allocation, or why there are none.
     *
     * @return array{rows: list<array<string, mixed>>, placed: array<int, array<int|string, array<string, int>>>, payments: Collection<int, object>, periods: Collection<int, object>}|string
     */
    private function plan(object $loan): array|string
    {
        $payments = DB::table('repayments')->where('loan_id', $loan->id)->orderBy('id')->get();

        if ($payments->contains('status', 'voided')) {
            return 'a payment on it was voided';
        }

        $posted = $payments->where('status', 'posted')->values();

        if (DB::table('repayment_allocations')->whereIn('repayment_id', $posted->pluck('id'))->exists()) {
            return 'some of its payments already record the periods they paid';
        }

        if ($posted->contains(fn (object $p) => (float) $p->penalty_applied > 0)) {
            return 'a payment on it paid penalty, which depends on dates that cannot be replayed';
        }

        $adjustments = DB::table('loan_adjustments')
            ->where('loan_id', $loan->id)
            ->where('status', 'applied')
            ->orderBy('id')
            ->get();

        if ($adjustments->whereIn('adjustment_type', ['restructure', 'term_extension'])->isNotEmpty()) {
            return 'a restructure or term extension numbered its new periods over the ones it deleted';
        }

        $extensions = $adjustments->where('adjustment_type', 'extension')->values()->map(fn (object $e) => (object) [
            'id' => $e->id,
            'number' => $e->adjustment_number,
            'created_at' => Carbon::parse($e->created_at),
            'old' => json_decode($e->old_values ?? '[]', true) ?: [],
            'new' => json_decode($e->new_values ?? '[]', true) ?: [],
        ]);

        if ($extensions->isEmpty()) {
            return 'no extension replaced any of its periods';
        }

        foreach ($extensions as $k => $extension) {
            if (! isset($extension->old['open_schedule_due_date'], $extension->new['new_due_date'])) {
                return "extension {$extension->number} did not record its due dates";
            }

            if ($k > 0 && $extension->old['open_schedule_due_date'] !== $extensions[$k - 1]->new['new_due_date']) {
                return "extension {$extension->number} did not start from the due date the one before it set";
            }
        }

        $periods = DB::table('amortization_schedules')->where('loan_id', $loan->id)->orderBy('period_number')->get();

        $missing = [];
        foreach (self::COMPONENTS as $component => [$total, $paid]) {
            $missing[$component] = $this->centavos($posted->sum($total)) - $this->centavos($periods->sum($paid));

            if ($missing[$component] < 0) {
                return 'its periods hold more than its payments';
            }
        }

        $firstReplaced = $this->firstReplacedPeriod($loan, $extensions, $periods);

        if ($firstReplaced === null) {
            return 'the period numbers its extensions replaced cannot be told';
        }

        // What each extension's period held: the payments made while it was
        // the open period. The first one takes the rest of what is missing.
        $held = $extensions->map(fn () => array_fill_keys(array_keys(self::COMPONENTS), 0))->all();

        foreach ($posted as $payment) {
            $k = $extensions->search(fn (object $e) => Carbon::parse($payment->created_at)->lte($e->created_at));

            if ($k === false || $k === 0) {
                continue;
            }

            foreach (self::COMPONENTS as $component => [$total]) {
                $held[$k][$component] += $this->centavos($payment->{$total});
            }
        }

        foreach (array_keys(self::COMPONENTS) as $component) {
            $held[0][$component] = $missing[$component] - array_sum(array_column(array_slice($held, 1), $component));

            if ($held[0][$component] < 0) {
                return 'its payments do not fit the periods its extensions replaced';
            }
        }

        $rows = [];
        foreach ($extensions as $k => $extension) {
            if (array_sum($held[$k]) === 0) {
                continue;
            }

            $periodNumber = $firstReplaced + $k;

            if ($periods->contains('period_number', $periodNumber)) {
                return "period {$periodNumber}, which extension {$extension->number} replaced, exists again";
            }

            $rows[] = [
                'key' => "rebuilt-{$periodNumber}",
                'period_number' => $periodNumber,
                'due_date' => $extension->old['open_schedule_due_date'],
                'penalty' => $held[$k]['penalty'],
                'interest' => $held[$k]['interest'],
                'principal' => $held[$k]['principal'],
                'remaining_balance' => (float) ($extension->old['open_principal'] ?? 0),
                'extension_id' => $extension->id,
                'extension_number' => $extension->number,
            ];
        }

        if ($rows === []) {
            return 'the missing payments fit no period an extension replaced';
        }

        $placed = $this->replay($posted, $this->withRebuilt($periods, $rows));

        if (is_string($placed)) {
            return $placed;
        }

        return ['rows' => $rows, 'placed' => $placed, 'payments' => $posted, 'periods' => $periods];
    }

    /**
     * The period number the first extension replaced.
     *
     * Counted back from the period the last extension created, while it still
     * exists; otherwise, on a loan with no periods left at all, the last
     * period of its original schedule. Anything else is a guess.
     *
     * @param  Collection<int, object>  $extensions
     * @param  Collection<int, object>  $periods
     */
    private function firstReplacedPeriod(object $loan, Collection $extensions, Collection $periods): ?int
    {
        $last = $extensions->last();

        $created = $periods->first(fn (object $p) => Carbon::parse($p->due_date)->toDateString() === $last->new['new_due_date']
            && abs(Carbon::parse($p->created_at)->diffInSeconds($last->created_at)) <= self::SAME_WRITE_SECONDS);

        if ($created) {
            $first = (int) $created->period_number - $extensions->count();

            return $first >= 1 ? $first : null;
        }

        if ($periods->isNotEmpty() || ! isset($extensions->first()->old['term'])) {
            return null;
        }

        return count(LoanTermSchedule::instalments(
            Carbon::parse($loan->start_date),
            (int) $extensions->first()->old['term'],
            $loan->term_unit,
            $loan->frequency,
        )) ?: null;
    }

    /**
     * The loan's periods with the rebuilt ones among them, in period order.
     *
     * @param  Collection<int, object>  $periods
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, object>
     */
    private function withRebuilt(Collection $periods, array $rows): Collection
    {
        $rebuilt = collect($rows)->map(fn (array $row) => (object) [
            'id' => $row['key'],
            'period_number' => $row['period_number'],
            'penalty_paid' => $row['penalty'] / 100,
            'interest_paid' => $row['interest'] / 100,
            'interest_due' => $row['interest'] / 100,
            'principal_paid' => $row['principal'] / 100,
            'principal_due' => $row['principal'] / 100,
        ]);

        return $periods->concat($rebuilt)->sortBy('period_number')->values();
    }

    /**
     * Each payment's centavos per period and component, replayed in period
     * order, or the first place the replay disagrees with what is recorded.
     *
     * @param  Collection<int, object>  $payments
     * @param  Collection<int, object>  $periods
     * @return array<int, array<int|string, array<string, int>>>|string
     */
    private function replay(Collection $payments, Collection $periods): array|string
    {
        $placed = [];
        $filled = [];

        foreach (self::COMPONENTS as $component => [$total, , $capacity]) {
            foreach ($payments as $payment) {
                $left = $this->centavos($payment->{$total});

                foreach ($periods as $period) {
                    $room = $this->centavos($period->{$capacity}) - ($filled[$period->id][$component] ?? 0);
                    $take = min(max(0, $room), $left);

                    if ($take > 0) {
                        $filled[$period->id][$component] = ($filled[$period->id][$component] ?? 0) + $take;
                        $placed[$payment->id][$period->id][$component] = $take;
                        $left -= $take;
                    }
                }

                if ($left > 0) {
                    return "replaying payment {$payment->receipt_number} leaves {$component} the periods cannot hold";
                }
            }
        }

        foreach ($periods as $period) {
            foreach (self::COMPONENTS as $component => [, $paid]) {
                if (($filled[$period->id][$component] ?? 0) !== $this->centavos($period->{$paid})) {
                    return "replaying its payments does not reproduce period {$period->period_number}'s {$component}";
                }
            }
        }

        return $placed;
    }

    /**
     * @param  array{rows: list<array<string, mixed>>, placed: array<int, array<int|string, array<string, int>>>, payments: Collection<int, object>, periods: Collection<int, object>}  $plan
     */
    private function write(object $loan, array $plan): void
    {
        $now = now();
        $ids = [];

        foreach ($plan['rows'] as $row) {
            $ids[$row['key']] = DB::table('amortization_schedules')->insertGetId([
                'loan_id' => $loan->id,
                'period_number' => $row['period_number'],
                'due_date' => $row['due_date'],
                'principal_due' => $row['principal'] / 100,
                'interest_due' => $row['interest'] / 100,
                'total_due' => ($row['principal'] + $row['interest']) / 100,
                'remaining_balance' => $row['remaining_balance'],
                'status' => 'paid',
                'principal_paid' => $row['principal'] / 100,
                'interest_paid' => $row['interest'] / 100,
                'penalty_amount' => $row['penalty'] / 100,
                'penalty_paid' => $row['penalty'] / 100,
                'closed_by_adjustment_id' => $row['extension_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $periodNumbers = $plan['periods']->pluck('period_number', 'id')
            ->union(collect($plan['rows'])->pluck('period_number', 'key'));

        $allocations = [];
        foreach ($plan['payments'] as $payment) {
            foreach ($plan['placed'][$payment->id] ?? [] as $periodId => $amounts) {
                $allocations[] = [
                    'repayment_id' => $payment->id,
                    'amortization_schedule_id' => $ids[$periodId] ?? $periodId,
                    'period_number' => $periodNumbers[$periodId],
                    'penalty' => ($amounts['penalty'] ?? 0) / 100,
                    'interest' => ($amounts['interest'] ?? 0) / 100,
                    'principal' => ($amounts['principal'] ?? 0) / 100,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('repayment_allocations')->insert($allocations);

        $periods = $this->describePeriods($plan);
        $restored = round(collect($periods)->sum(fn (array $p) => $p['principal'] + $p['interest'] + $p['penalty']), 2);

        DB::table('audit_logs')->insert([
            'user_id' => null,
            'action' => self::AUDIT_ACTION,
            'auditable_type' => (new Loan)->getMorphClass(),
            'auditable_id' => $loan->id,
            'old_values' => json_encode(['periods' => []]),
            'new_values' => json_encode(['periods' => $periods, 'restored' => $restored]),
            'ip_address' => null,
            'user_agent' => null,
            'description' => sprintf(
                'Rebuilt %d period(s) of loan %s that an extension had deleted, putting back the ₱%s collected on them.',
                count($periods),
                $loan->loan_account_number ?? $loan->application_number,
                number_format($restored, 2),
            ),
            'created_at' => $now,
        ]);
    }

    /**
     * @param  array{rows: list<array<string, mixed>>, placed: array<int, array<int|string, array<string, int>>>, payments: Collection<int, object>}  $plan
     * @return list<array<string, mixed>>
     */
    private function describePeriods(array $plan): array
    {
        $receipts = $plan['payments']->pluck('receipt_number', 'id');

        return array_map(fn (array $row) => [
            'period_number' => $row['period_number'],
            'due_date' => $row['due_date'],
            'principal' => $row['principal'] / 100,
            'interest' => $row['interest'] / 100,
            'penalty' => $row['penalty'] / 100,
            'closed_by' => $row['extension_number'],
            'payments' => collect($plan['placed'])
                ->filter(fn (array $byPeriod) => isset($byPeriod[$row['key']]))
                ->keys()
                ->map(fn (int $paymentId) => $receipts[$paymentId])
                ->values()
                ->all(),
        ], $plan['rows']);
    }

    private function centavos(mixed $pesos): int
    {
        return (int) round((float) $pesos * 100);
    }
}
