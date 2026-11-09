<?php

namespace App\Services\Accounting;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Services\LikePattern;
use App\Services\LoanListTotals;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Receivable aging loan by loan, and member by member: the rows behind
 * {@see ReceivableAgingBuilder}'s buckets.
 *
 * - The loans: collectible ({@see Loan::COLLECTIBLE_STATUSES}, defaulted
 *   included) with something still unpaid, a period or the premium owed.
 * - `days_past_due` counts from the loan's oldest unpaid period, from its
 *   bare due date, and `bucket` comes from the same CASE the aging report
 *   uses ({@see ReceivableAgingBuilder::bucketCaseSql()}). A loan with no
 *   period due before `as_of` is 0 days and `current`.
 * - `is_past_due` is the grace rule ({@see AmortizationSchedule::pastGraceSql()},
 *   the rule behind Loan::scopePastDue() and `display_status`), so a loan can
 *   sit in `1_30` and still not be past due.
 * - Money comes from {@see LoanListTotals}: byLoan() for a page's rows and
 *   for() for `meta.totals`, on the same filtered query, so the totals cover
 *   every matching row and never a sum of the page. Pesos are converted to
 *   integer centavos once, here.
 *
 * Reads only. C4's allowance base reads self::rows().
 */
final class LoanAgingBuilder
{
    public const GROUPS = ['loan', 'member'];

    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /**
     * The aged date of a loan with no unpaid period: later than any real due
     * date, so the shared CASE reads it as `current`.
     */
    private const NO_DUE_DATE = "'9999-12-31'";

    private const OLDEST_DUE = '`loan_aging`.`oldest_unpaid_due`';

    public function __construct(private LoanListTotals $totals) {}

    /**
     * Every matching loan as a loan row, unpaged, oldest first.
     *
     * @param  int|list<int>|null  $branchId  the branch scope (null: every branch)
     * @param  array{bucket?: string|null, past_due_only?: bool, search?: string|null}  $filters
     * @param  Builder<Loan>|null  $loans  a starting query, such as Loan::visibleTo($user)
     * @return list<array<string, mixed>>
     */
    public function rows(string $asOf, int|array|null $branchId = null, array $filters = [], ?Builder $loans = null): array
    {
        $aged = $this->ordered($this->query($asOf, $branchId, $filters, $loans));

        return $this->loanRows($this->withRowRelations($aged)->get(), $asOf);
    }

    /**
     * The aged loans as a Loan query, with the columns `oldest_unpaid_due`,
     * `oldest_late_due_date`, `days_past_due`, `bucket` and `is_past_due`,
     * and the filters applied in SQL.
     *
     * @param  int|list<int>|null  $branchId
     * @param  array{bucket?: string|null, past_due_only?: bool, search?: string|null}  $filters
     * @param  Builder<Loan>|null  $loans
     * @return Builder<Loan>
     */
    public function query(string $asOf, int|array|null $branchId = null, array $filters = [], ?Builder $loans = null): Builder
    {
        $query = $this->filtered($asOf, $branchId, $filters, $loans);

        if (filled($filters['bucket'] ?? null)) {
            $query->whereRaw($this->bucketSql().' = ?', [...$this->asOfTimes(5, $asOf), $filters['bucket']]);
        }

        return $query->select('loans.*')->selectRaw($this->ageColumnsSql(), $this->ageBindings($asOf));
    }

    /**
     * One page of loan rows (`group=loan`) or member rows (`group=member`),
     * with `totals` over every matching row and `stats`, the rows per bucket
     * ignoring the bucket filter.
     *
     * @param  int|list<int>|null  $branchId
     * @param  array{bucket?: string|null, past_due_only?: bool, search?: string|null}  $filters
     * @param  Builder<Loan>|null  $loans
     * @return array{rows: LengthAwarePaginator, totals: array{count: int, outstanding_principal: int, total_outstanding: int, past_due_amount: int}, stats: array<string, int>}
     */
    public function page(
        string $asOf,
        int|array|null $branchId,
        array $filters,
        string $group = 'loan',
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
        ?Builder $loans = null,
    ): array {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        return $group === 'member'
            ? $this->memberPage($asOf, $branchId, $filters, $page, $perPage, $loans)
            : $this->loanPage($asOf, $branchId, $filters, $page, $perPage, $loans);
    }

    /**
     * @param  int|list<int>|null  $branchId
     * @param  array<string, mixed>  $filters
     * @param  Builder<Loan>|null  $loans
     * @return array{rows: LengthAwarePaginator, totals: array<string, int>, stats: array<string, int>}
     */
    private function loanPage(string $asOf, int|array|null $branchId, array $filters, int $page, int $perPage, ?Builder $loans): array
    {
        $matching = $this->query($asOf, $branchId, $filters, $loans);

        $paginator = $this->withRowRelations($this->ordered(clone $matching))
            ->paginate($perPage, ['*'], 'page', $page);

        $paginator->setCollection(collect($this->loanRows($paginator->getCollection(), $asOf)));

        $stats = DB::query()
            ->fromSub($this->ageOnly($asOf, $branchId, $filters, $loans), 'aged')
            ->groupBy('aged.bucket')
            ->selectRaw('aged.bucket, COUNT(*) as row_count')
            ->pluck('row_count', 'bucket');

        return [
            'rows' => $paginator,
            'totals' => $this->totalsOf($matching, $paginator->total(), $asOf),
            'stats' => $this->statsOf($stats->all()),
        ];
    }

    /**
     * Members are grouped from the loans the other filters match; a member's
     * bucket is their worst loan's, and the bucket filter reads that bucket,
     * so a member row always carries every one of their matching loans.
     *
     * @param  int|list<int>|null  $branchId
     * @param  array<string, mixed>  $filters
     * @param  Builder<Loan>|null  $loans
     * @return array{rows: LengthAwarePaginator, totals: array<string, int>, stats: array<string, int>}
     */
    private function memberPage(string $asOf, int|array|null $branchId, array $filters, int $page, int $perPage, ?Builder $loans): array
    {
        $bucket = $filters['bucket'] ?? null;
        $unbucketed = array_merge($filters, ['bucket' => null]);

        $members = $this->membersQuery($asOf, $branchId, $unbucketed, $loans, $bucket);
        $total = DB::query()->fromSub(clone $members, 'members')->count();

        $memberRows = (clone $members)
            ->orderByDesc('days_past_due')
            ->orderBy('borrowers.last_name')
            ->orderBy('borrowers.first_name')
            ->orderBy('borrowers.id')
            ->forPage($page, $perPage)
            ->get();

        $paginator = new LengthAwarePaginator(
            $this->memberRows($memberRows, $asOf, $branchId, $unbucketed, $loans),
            $total,
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        );

        $matchingLoans = $this->filtered($asOf, $branchId, $unbucketed, $loans)
            ->when(filled($bucket), fn (Builder $q) => $q->whereIn(
                'loans.borrower_id',
                $this->membersQuery($asOf, $branchId, $unbucketed, $loans, $bucket)->select('borrowers.id'),
            ));

        $stats = DB::query()
            ->fromSub($this->membersQuery($asOf, $branchId, $unbucketed, $loans, null), 'members')
            ->groupBy('members.bucket_rank')
            ->selectRaw('members.bucket_rank, COUNT(*) as row_count')
            ->pluck('row_count', 'bucket_rank')
            ->mapWithKeys(fn ($count, $rank) => [ReceivableAgingBuilder::BUCKETS[(int) $rank - 1] => $count]);

        return [
            'rows' => $paginator,
            'totals' => $this->totalsOf($matchingLoans, $total, $asOf),
            'stats' => $this->statsOf($stats->all()),
        ];
    }

    /**
     * One row per member: `borrowers.id`, `loan_count`, `days_past_due` (the
     * worst loan's) and `bucket_rank` (1-based in BUCKETS, the worst loan's).
     *
     * @param  int|list<int>|null  $branchId
     * @param  array<string, mixed>  $filters  without the bucket filter
     * @param  Builder<Loan>|null  $loans
     */
    private function membersQuery(string $asOf, int|array|null $branchId, array $filters, ?Builder $loans, ?string $bucket): QueryBuilder
    {
        $rank = $this->bucketRankSql('aged.bucket');

        return DB::query()
            ->fromSub($this->ageOnly($asOf, $branchId, $filters, $loans), 'aged')
            ->join('borrowers', 'borrowers.id', '=', 'aged.borrower_id')
            ->groupBy('borrowers.id')
            ->selectRaw("borrowers.id, COUNT(*) as loan_count, MAX(aged.days_past_due) as days_past_due, MAX({$rank}) as bucket_rank")
            ->when(filled($bucket), fn (QueryBuilder $q) => $q->havingRaw(
                "MAX({$rank}) = ?",
                [array_search($bucket, ReceivableAgingBuilder::BUCKETS, true) + 1],
            ));
    }

    /**
     * @param  Collection<int, object>  $members
     * @param  int|list<int>|null  $branchId
     * @param  array<string, mixed>  $filters  without the bucket filter
     * @param  Builder<Loan>|null  $loans
     * @return list<array<string, mixed>>
     */
    private function memberRows(Collection $members, string $asOf, int|array|null $branchId, array $filters, ?Builder $loans): array
    {
        if ($members->isEmpty()) {
            return [];
        }

        $memberIds = $members->pluck('id')->map(fn ($id) => (int) $id)->all();

        $loanOwners = $this->filtered($asOf, $branchId, $filters, $loans)
            ->whereIn('loans.borrower_id', $memberIds)
            ->pluck('loans.borrower_id', 'loans.id');

        $money = [];
        foreach ($this->totals->byLoan($loanOwners->keys()->all(), Carbon::parse($asOf)) as $loanId => $figures) {
            $memberId = (int) $loanOwners[$loanId];
            $money[$memberId]['outstanding_principal'] = ($money[$memberId]['outstanding_principal'] ?? 0) + $this->centavos($figures['outstanding_principal']);
            $money[$memberId]['total_outstanding'] = ($money[$memberId]['total_outstanding'] ?? 0) + $this->centavos($figures['total_outstanding']);
            $money[$memberId]['past_due_amount'] = ($money[$memberId]['past_due_amount'] ?? 0) + $this->centavos($figures['overdue_amount']);
        }

        $people = Borrower::query()
            ->whereIn('id', $memberIds)
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'borrower_code'])
            ->keyBy('id');

        return $members->map(function (object $member) use ($money, $people): array {
            $id = (int) $member->id;

            return [
                'member' => $this->member($people[$id] ?? null, $id),
                'loan_count' => (int) $member->loan_count,
                'outstanding_principal' => $money[$id]['outstanding_principal'] ?? 0,
                'total_outstanding' => $money[$id]['total_outstanding'] ?? 0,
                'past_due_amount' => $money[$id]['past_due_amount'] ?? 0,
                'days_past_due' => (int) $member->days_past_due,
                'bucket' => ReceivableAgingBuilder::BUCKETS[(int) $member->bucket_rank - 1],
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, Loan>  $loans  loaded through self::withRowRelations()
     * @return list<array<string, mixed>>
     */
    private function loanRows(Collection $loans, string $asOf): array
    {
        $money = $this->totals->byLoan($loans->modelKeys(), Carbon::parse($asOf));

        return $loans->map(fn (Loan $loan): array => [
            'loan_id' => $loan->id,
            'account_number' => $loan->loan_account_number,
            'member' => $this->member($loan->borrower, (int) $loan->borrower_id),
            'branch' => $loan->branch === null ? null : ['id' => $loan->branch->id, 'name' => $loan->branch->name],
            'display_status' => $loan->displayStatus(),
            'oldest_late_due_date' => $loan->getAttribute('oldest_late_due_date') === null
                ? null
                : Carbon::parse($loan->getAttribute('oldest_late_due_date'))->toDateString(),
            'days_past_due' => (int) $loan->getAttribute('days_past_due'),
            'bucket' => (string) $loan->getAttribute('bucket'),
            'is_past_due' => (bool) $loan->getAttribute('is_past_due'),
            'outstanding_principal' => $this->centavos($money[$loan->id]['outstanding_principal'] ?? 0.0),
            'total_outstanding' => $this->centavos($money[$loan->id]['total_outstanding'] ?? 0.0),
            'past_due_amount' => $this->centavos($money[$loan->id]['overdue_amount'] ?? 0.0),
        ])->values()->all();
    }

    /**
     * A member as a loan row shows them: id, name and code, never contact or
     * personal fields.
     *
     * @return array{id: int, name: string|null, code: string|null}
     */
    private function member(?Borrower $member, int $id): array
    {
        return [
            'id' => $id,
            'name' => $member?->full_name,
            'code' => $member?->borrower_code,
        ];
    }

    /**
     * @param  Builder<Loan>  $matching
     * @return array{count: int, outstanding_principal: int, total_outstanding: int, past_due_amount: int}
     */
    private function totalsOf(Builder $matching, int $count, string $asOf): array
    {
        $totals = $this->totals->for($matching, Carbon::parse($asOf));

        return [
            'count' => $count,
            'outstanding_principal' => $this->centavos($totals['outstanding_principal']),
            'total_outstanding' => $this->centavos($totals['total_outstanding']),
            'past_due_amount' => $this->centavos($totals['overdue_amount']),
        ];
    }

    /**
     * @param  array<string, int|string>  $counts
     * @return array<string, int>
     */
    private function statsOf(array $counts): array
    {
        $stats = [];
        foreach (ReceivableAgingBuilder::BUCKETS as $bucket) {
            $stats[$bucket] = (int) ($counts[$bucket] ?? 0);
        }

        return $stats;
    }

    /**
     * The loans in scope with something unpaid, and the past-due and search
     * filters; no columns, order or bucket filter.
     *
     * @param  int|list<int>|null  $branchId
     * @param  array<string, mixed>  $filters
     * @param  Builder<Loan>|null  $loans
     * @return Builder<Loan>
     */
    private function filtered(string $asOf, int|array|null $branchId, array $filters, ?Builder $loans): Builder
    {
        $remaining = AmortizationSchedule::remainingTotalSql();

        $oldestUnpaid = DB::table('amortization_schedules')
            ->join('loans', 'loans.id', '=', 'amortization_schedules.loan_id')
            ->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES)
            ->when($branchId !== null, fn (QueryBuilder $q) => $q->whereIn('loans.branch_id', (array) $branchId))
            ->whereIn('amortization_schedules.status', AmortizationSchedule::UNPAID_STATUSES)
            ->whereRaw("{$remaining} > 0")
            ->groupBy('amortization_schedules.loan_id')
            ->selectRaw('amortization_schedules.loan_id, MIN(amortization_schedules.due_date) as oldest_unpaid_due');

        $search = trim((string) ($filters['search'] ?? ''));

        return (clone ($loans ?? Loan::query()))
            ->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES)
            ->when($branchId !== null, fn (Builder $q) => $q->whereIn('loans.branch_id', (array) $branchId))
            ->leftJoinSub($oldestUnpaid, 'loan_aging', 'loan_aging.loan_id', '=', 'loans.id')
            ->where(fn (Builder $q) => $q
                ->whereNotNull('loan_aging.loan_id')
                ->orWhere('loans.insurance_remaining_balance', '>', 0))
            ->when((bool) ($filters['past_due_only'] ?? false), fn (Builder $q) => $q->whereRaw(
                $this->pastDueSql(),
                [...AmortizationSchedule::UNPAID_STATUSES, $asOf],
            ))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $match) => $match
                ->where('loans.loan_account_number', 'like', LikePattern::contains($search))
                ->orWhereHas('borrower', fn (Builder $member) => $member->search($search, false))));
    }

    /**
     * `loans.id`, `loans.borrower_id`, `bucket` and `days_past_due` of the
     * filtered loans, for grouping.
     *
     * @param  int|list<int>|null  $branchId
     * @param  array<string, mixed>  $filters
     * @param  Builder<Loan>|null  $loans
     */
    private function ageOnly(string $asOf, int|array|null $branchId, array $filters, ?Builder $loans): QueryBuilder
    {
        return $this->filtered($asOf, $branchId, $filters, $loans)
            ->toBase()
            ->select('loans.id', 'loans.borrower_id')
            ->selectRaw(
                $this->bucketSql().' as bucket, GREATEST(DATEDIFF(?, '.$this->agedDateSql().'), 0) as days_past_due',
                [...$this->asOfTimes(5, $asOf), $asOf],
            );
    }

    /**
     * @param  Builder<Loan>  $query
     * @return Builder<Loan>
     */
    private function ordered(Builder $query): Builder
    {
        return $query
            ->orderByDesc('days_past_due')
            ->orderBy('loans.loan_account_number')
            ->orderBy('loans.id');
    }

    /**
     * What a loan row reads besides its own columns. The open periods let
     * Loan::displayStatus() answer without a query per row.
     *
     * @param  Builder<Loan>  $query
     * @return Builder<Loan>
     */
    private function withRowRelations(Builder $query): Builder
    {
        return $query->with([
            'borrower:id,first_name,middle_name,last_name,suffix,borrower_code',
            'branch:id,name',
            'openSchedules',
        ]);
    }

    private function ageColumnsSql(): string
    {
        $oldest = self::OLDEST_DUE;

        return implode(', ', [
            "{$oldest} as oldest_unpaid_due",
            "CASE WHEN {$oldest} < ? THEN {$oldest} END as oldest_late_due_date",
            'GREATEST(DATEDIFF(?, '.$this->agedDateSql().'), 0) as days_past_due',
            $this->bucketSql().' as bucket',
            $this->pastDueSql().' as is_past_due',
        ]);
    }

    /**
     * @return list<string>
     */
    private function ageBindings(string $asOf): array
    {
        return [$asOf, $asOf, ...$this->asOfTimes(5, $asOf), ...AmortizationSchedule::UNPAID_STATUSES, $asOf];
    }

    /** Five bindings, all the as-of date. */
    private function bucketSql(): string
    {
        return ReceivableAgingBuilder::bucketCaseSql($this->agedDateSql());
    }

    private function agedDateSql(): string
    {
        return 'COALESCE('.self::OLDEST_DUE.', '.self::NO_DUE_DATE.')';
    }

    /**
     * Whether the loan holds an unpaid period past its due date and grace on
     * `as_of`: Loan::scopePastDue()'s test, through the one definition of
     * late. Bindings: the unpaid statuses, then the as-of date.
     */
    private function pastDueSql(): string
    {
        $unpaid = implode(', ', array_fill(0, count(AmortizationSchedule::UNPAID_STATUSES), '?'));

        return 'EXISTS (SELECT 1 FROM `amortization_schedules` WHERE `amortization_schedules`.`loan_id` = `loans`.`id`'
            ." AND `amortization_schedules`.`status` IN ({$unpaid}) AND ".AmortizationSchedule::pastGraceSql().')';
    }

    /** A bucket's 1-based place in BUCKETS, youngest first. */
    private function bucketRankSql(string $column): string
    {
        $buckets = implode(', ', array_map(fn (string $bucket) => "'{$bucket}'", ReceivableAgingBuilder::BUCKETS));

        return "FIELD({$column}, {$buckets})";
    }

    /**
     * @return list<string>
     */
    private function asOfTimes(int $times, string $asOf): array
    {
        return array_fill(0, $times, $asOf);
    }

    /**
     * Lending pesos to accounting centavos, once. A figure Money will not
     * convert is an anomaly on a provisioning base, so it fails loudly, as
     * ReceivableAgingBuilder does.
     */
    private function centavos(float $pesos): int
    {
        $centavos = Money::toCentavos($pesos);

        if ($centavos === null) {
            throw new \RuntimeException("Loan aging: '{$pesos}' is not a convertible peso amount.");
        }

        return $centavos;
    }
}
