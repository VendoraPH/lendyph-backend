<?php

namespace App\Services;

use App\Models\Collateral;
use App\Models\Loan;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The Collateral Register: the collateral book grouped by member, then
 * filtered, sorted, totalled and paged by the database.
 *
 * It replaces a screen that drained every collateral, read one share capital
 * ledger per member, and grouped, filtered and summed in the browser. Every
 * figure here is the one that screen showed for the same data:
 *
 *  - Search and the type filter narrow ROWS. A member's group, and each of its
 *    figures, covers only the rows that survived.
 *  - A row's value is CollateralValuation's, restated in SQL so that groups can
 *    be sorted and totalled by it: the `amount`, or for share capital the
 *    member's whole-ledger balance (ShareCapitalLedger::balancesQuery()). A
 *    caller without `share_capital:view` gets no balance at all: those rows are
 *    unknown, left out of every total and counted instead, and the ledger is
 *    never read.
 *  - A row is tagged when a loan in Loan::ACTIVE_STATUSES holds it, which is
 *    exactly `active_loans` being non-empty.
 *
 * A member's name is the one the caller may see. Without `borrowers:view` every
 * member is "Member #{id}", and is displayed, searched AND sorted as that. A
 * search or a sort on the real name would let the list be used to recover the
 * very names it withholds.
 */
final class CollateralRegister
{
    public const SORTS = ['member', 'collaterals', 'total_value', 'tagged'];

    /**
     * The select alias each sort orders by.
     */
    private const SORT_ALIASES = [
        'member' => 'borrower_name',
        'collaterals' => 'collaterals_count',
        'total_value' => 'total_value',
        'tagged' => 'tagged_count',
    ];

    private readonly bool $namesVisible;

    private readonly bool $balancesVisible;

    public function __construct(
        User $viewer,
        private readonly ?string $search = null,
        private readonly ?int $collateralTypeId = null,
    ) {
        $this->namesVisible = $viewer->can('borrowers:view');
        $this->balancesVisible = $viewer->can('share_capital:view');
    }

    public function namesHidden(): bool
    {
        return ! $this->namesVisible;
    }

    /**
     * One page of member groups, each with its figures over its filtered rows.
     *
     * Ordered by the chosen figure, then by the name as displayed, then by
     * member id, so the order is total and a drained list never serves a group
     * twice or skips one (see DeterministicPaginationTest). The tiebreaks stay
     * ascending whichever way the primary sort runs.
     *
     * Rows are `borrower_id`, `borrower_name`, `collaterals_count`,
     * `tagged_count`, `total_value` and `unknown_count`, as the database
     * returns them.
     */
    public function groups(string $sort, string $direction, int $perPage): LengthAwarePaginator
    {
        return $this->withBalances($this->filtered()->toBase())
            ->selectRaw('borrowers.id as borrower_id')
            ->selectRaw($this->nameSql().' as borrower_name')
            ->selectRaw('COUNT(*) as collaterals_count')
            ->selectRaw('SUM('.$this->taggedSql().') as tagged_count', Loan::ACTIVE_STATUSES)
            ->selectRaw('COALESCE(SUM('.$this->knownValueSql().'), 0) as total_value')
            ->selectRaw('SUM('.$this->unknownSql().') as unknown_count')
            ->groupBy('borrowers.id')
            ->orderBy(self::SORT_ALIASES[$sort], $direction)
            ->when($sort !== 'member', fn (QueryBuilder $query) => $query->orderBy('borrower_name'))
            ->orderBy('borrowers.id')
            ->paginate($perPage);
    }

    /**
     * The filtered rows of the given members, newest first, with what
     * CollateralResource renders already loaded.
     *
     * `id` breaks a `created_at` tie, as it does on every list here, so a
     * member's rows come back in the same order on every request.
     *
     * @param  array<int, int>  $borrowerIds
     * @return EloquentCollection<int, Collateral>
     */
    public function rowsOf(array $borrowerIds): EloquentCollection
    {
        if ($borrowerIds === []) {
            return new EloquentCollection;
        }

        return $this->filtered()
            ->select('collaterals.*')
            ->whereIn('collaterals.borrower_id', $borrowerIds)
            ->with(['collateralType', 'activeLoans'])
            ->orderByDesc('collaterals.created_at')
            ->orderByDesc('collaterals.id')
            ->get();
    }

    /**
     * The register's headline figures.
     *
     * `total_collaterals` and `tagged_to_active_loans` describe the WHOLE book
     * and ignore search and type, as the screen's cards always have. The rest
     * follow the filter: the known value of the filtered rows, how many of
     * them are unknown, and how many members they belong to, which is the
     * paginator's group count.
     *
     * @return array{total_collaterals: int, tagged_to_active_loans: int, total_value: float, unknown_count: int, members: int}
     */
    public function totals(): array
    {
        $book = DB::table('collaterals')
            ->selectRaw('COUNT(*) as total_collaterals')
            ->selectRaw('COALESCE(SUM('.$this->taggedSql().'), 0) as tagged_to_active_loans', Loan::ACTIVE_STATUSES)
            ->first();

        $filtered = $this->withBalances($this->filtered()->toBase())
            ->selectRaw('COUNT(DISTINCT collaterals.borrower_id) as members')
            ->selectRaw('COALESCE(SUM('.$this->knownValueSql().'), 0) as total_value')
            ->selectRaw('COALESCE(SUM('.$this->unknownSql().'), 0) as unknown_count')
            ->first();

        return [
            'total_collaterals' => (int) $book->total_collaterals,
            'tagged_to_active_loans' => (int) $book->tagged_to_active_loans,
            'total_value' => round((float) $filtered->total_value, 2),
            'unknown_count' => (int) $filtered->unknown_count,
            'members' => (int) $filtered->members,
        ];
    }

    /**
     * Collateral rows with their member and type joined, narrowed by search
     * and type. Every query here starts from this one, so the groups, the rows
     * inside them and the totals can never disagree about which rows count.
     *
     * Search is a case-insensitive substring match (the column collation is
     * `_ci`) over the member's name as displayed, the detail, and the type's
     * name. `%` and `_` in the term are matched literally.
     */
    private function filtered(): Builder
    {
        return Collateral::query()
            ->join('borrowers', 'borrowers.id', '=', 'collaterals.borrower_id')
            ->join('collateral_types', 'collateral_types.id', '=', 'collaterals.collateral_type_id')
            ->when(filled($this->collateralTypeId), fn (Builder $query) => $query
                ->where('collaterals.collateral_type_id', $this->collateralTypeId))
            ->when(filled($this->search), function (Builder $query) {
                $like = '%'.addcslashes((string) $this->search, '\\%_').'%';

                $query->where(fn (Builder $matches) => $matches
                    ->whereRaw($this->nameSql().' LIKE ?', [$like])
                    ->orWhere('collaterals.detail_value', 'like', $like)
                    ->orWhere('collateral_types.name', 'like', $like));
            });
    }

    /**
     * Join each member's share capital balance, for a caller allowed to see it.
     *
     * Narrowed to members holding a share capital collateral, so the ledger
     * aggregate covers the members it can matter to rather than every member
     * who ever contributed. Not joined at all for anyone else: their share
     * capital rows are unknown, and the ledger is not read on their behalf.
     */
    private function withBalances(QueryBuilder $query): QueryBuilder
    {
        if (! $this->balancesVisible) {
            return $query;
        }

        $holders = DB::table('collaterals')
            ->join('collateral_types', 'collateral_types.id', '=', 'collaterals.collateral_type_id')
            ->where('collateral_types.source', 'share_capital')
            ->select('collaterals.borrower_id');

        return $query->leftJoinSub(
            ShareCapitalLedger::balancesQuery()->whereIn('borrower_id', $holders),
            'share_capital_balances',
            'share_capital_balances.borrower_id',
            '=',
            'collaterals.borrower_id',
        );
    }

    /**
     * The member's name as the caller sees it. Borrower::full_name when names
     * are visible: first, middle, last and suffix, empty parts dropped.
     */
    private function nameSql(): string
    {
        return $this->namesVisible
            ? "CONCAT_WS(' ', NULLIF(borrowers.first_name, ''), NULLIF(borrowers.middle_name, ''), NULLIF(borrowers.last_name, ''), NULLIF(borrowers.suffix, ''))"
            : "CONCAT('Member #', borrowers.id)";
    }

    /**
     * A row's value when it is known, NULL when it is not, so SUM() skips the
     * unknowns rather than counting them as 0. A member with no ledger entries
     * has a known balance of 0.
     */
    private function knownValueSql(): string
    {
        $shareCapital = $this->balancesVisible ? 'COALESCE(share_capital_balances.balance, 0)' : 'NULL';

        return "CASE WHEN collateral_types.source = 'share_capital' THEN {$shareCapital} ELSE collaterals.amount END";
    }

    private function unknownSql(): string
    {
        return $this->balancesVisible
            ? '0'
            : "CASE WHEN collateral_types.source = 'share_capital' THEN 1 ELSE 0 END";
    }

    /**
     * 1 when a loan in Loan::ACTIVE_STATUSES holds the row, else 0. Takes the
     * statuses as bindings.
     */
    private function taggedSql(): string
    {
        $statuses = implode(', ', array_fill(0, count(Loan::ACTIVE_STATUSES), '?'));

        return 'CASE WHEN EXISTS (SELECT 1 FROM loan_collaterals'
            .' INNER JOIN loans ON loans.id = loan_collaterals.loan_id'
            .' WHERE loan_collaterals.collateral_id = collaterals.id'
            ." AND loans.status IN ({$statuses})) THEN 1 ELSE 0 END";
    }
}
