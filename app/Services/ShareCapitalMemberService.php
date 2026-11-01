<?php

namespace App\Services;

use App\Models\Borrower;
use App\Models\ShareCapitalLedger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Subsidiary Ledger's figures, so the browser never adds a member's share
 * capital up itself: each member's credits, debits and balance over every
 * entry (GET /share-capital/members), and the running balance and period
 * totals of one member's entries (GET /share-capital/ledger?borrower_id=).
 *
 * A running balance runs in posting order: by date, then by id, so entries on
 * the same day run in the order they were made, as the share capital
 * statement does. Money is summed in SQL on the DECIMAL columns, so it is
 * exact to the centavo.
 */
class ShareCapitalMemberService
{
    /**
     * One row per member with ledger entries, paginated, with the totals of
     * the whole book (`totals`) and of the members matching `$search`
     * (`matching_totals`).
     *
     * @return array{members: LengthAwarePaginator, totals: array<string, float|int>, matching_totals: array<string, float|int>}
     */
    public function members(?string $search, string $sort, string $direction, int $perPage): array
    {
        $matching = $this->ledgerWithBorrowers($search);

        $query = (clone $matching)
            ->groupBy('borrowers.id', 'borrowers.borrower_code', 'borrowers.last_name', 'borrowers.first_name')
            ->select('borrowers.id as borrower_id')
            ->selectRaw('SUM(share_capital_ledger.credit) as total_credits')
            ->selectRaw('SUM(share_capital_ledger.debit) as total_debits')
            ->selectRaw('SUM(share_capital_ledger.credit) - SUM(share_capital_ledger.debit) as balance')
            ->selectRaw('COUNT(*) as entry_count')
            ->selectRaw('MAX(share_capital_ledger.date) as last_transaction_date');

        if ($sort === 'balance') {
            $query->orderBy('balance', $direction);
        } else {
            $query->orderBy('borrowers.last_name', $direction)->orderBy('borrowers.first_name', $direction);
        }

        $members = $query->orderBy('borrowers.id')->paginate($perPage);

        $borrowers = Borrower::query()
            ->whereIn('id', collect($members->items())->pluck('borrower_id'))
            ->get()
            ->keyBy('id');

        $members->through(fn (object $row): array => [
            'borrower_id' => (int) $row->borrower_id,
            'borrower_code' => $borrowers->get($row->borrower_id)?->borrower_code,
            'full_name' => $borrowers->get($row->borrower_id)?->full_name,
            'total_credits' => $this->pesos($row->total_credits),
            'total_debits' => $this->pesos($row->total_debits),
            'balance' => $this->pesos($row->balance),
            'entry_count' => (int) $row->entry_count,
            'last_transaction_date' => $row->last_transaction_date,
        ]);

        return [
            'members' => $members,
            'totals' => $this->totals($this->ledgerWithBorrowers(null)),
            'matching_totals' => $this->totals($matching),
        ];
    }

    /**
     * Adds `running_balance` to every ledger row the query returns: the
     * member's balance after that entry, over all of the member's entries up
     * to it, whatever else the query filters by.
     *
     * @param  EloquentBuilder<ShareCapitalLedger>  $query
     * @return EloquentBuilder<ShareCapitalLedger>
     */
    public function withRunningBalance(EloquentBuilder $query): EloquentBuilder
    {
        return $query
            ->select('share_capital_ledger.*')
            ->selectSub(function (Builder $earlier): void {
                $earlier->from('share_capital_ledger as earlier')
                    ->selectRaw('COALESCE(SUM(earlier.credit - earlier.debit), 0)')
                    ->whereColumn('earlier.borrower_id', 'share_capital_ledger.borrower_id')
                    ->where(function (Builder $before): void {
                        $before->whereColumn('earlier.date', '<', 'share_capital_ledger.date')
                            ->orWhere(function (Builder $sameDay): void {
                                $sameDay->whereColumn('earlier.date', 'share_capital_ledger.date')
                                    ->whereColumn('earlier.id', '<=', 'share_capital_ledger.id');
                            });
                    });
            }, 'running_balance');
    }

    /**
     * One member's period figures: the balance before `$dateFrom` (0 when
     * there is no start), the credits and debits from `$dateFrom` to
     * `$dateTo`, and the balance at the end of it.
     *
     * @return array{opening_balance: float|int, total_credits: float|int, total_debits: float|int, ending_balance: float|int}
     */
    public function memberPeriod(int $borrowerId, ?string $dateFrom, ?string $dateTo): array
    {
        $ledger = fn (): Builder => DB::table('share_capital_ledger')->where('borrower_id', $borrowerId);

        $opening = $dateFrom === null ? 0 : $ledger()
            ->whereDate('date', '<', $dateFrom)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as balance')
            ->value('balance');

        $period = $ledger()
            ->when($dateFrom !== null, fn (Builder $q) => $q->whereDate('date', '>=', $dateFrom))
            ->when($dateTo !== null, fn (Builder $q) => $q->whereDate('date', '<=', $dateTo))
            ->selectRaw('COALESCE(SUM(credit), 0) as credits, COALESCE(SUM(debit), 0) as debits')
            ->first();

        $openingCentavos = LoanService::toCentavos((float) $opening);
        $credits = LoanService::toCentavos((float) $period->credits);
        $debits = LoanService::toCentavos((float) $period->debits);

        return [
            'opening_balance' => $openingCentavos / 100,
            'total_credits' => $credits / 100,
            'total_debits' => $debits / 100,
            'ending_balance' => ($openingCentavos + $credits - $debits) / 100,
        ];
    }

    private function ledgerWithBorrowers(?string $search): Builder
    {
        return DB::table('share_capital_ledger')
            ->join('borrowers', 'borrowers.id', '=', 'share_capital_ledger.borrower_id')
            ->when(filled($search), function (Builder $q) use ($search): void {
                $like = LikePattern::contains($search);

                $q->where(function (Builder $q) use ($like): void {
                    $q->where('borrowers.borrower_code', 'like', $like)
                        ->orWhere('borrowers.first_name', 'like', $like)
                        ->orWhere('borrowers.last_name', 'like', $like)
                        ->orWhereRaw("CONCAT(borrowers.first_name, ' ', borrowers.last_name) like ?", [$like]);
                });
            });
    }

    /**
     * @return array{member_count: int, total_credits: float|int, total_debits: float|int, balance: float|int}
     */
    private function totals(Builder $ledger): array
    {
        $row = (clone $ledger)
            ->selectRaw('COUNT(DISTINCT share_capital_ledger.borrower_id) as member_count')
            ->selectRaw('COALESCE(SUM(share_capital_ledger.credit), 0) as credits')
            ->selectRaw('COALESCE(SUM(share_capital_ledger.debit), 0) as debits')
            ->first();

        $credits = LoanService::toCentavos((float) $row->credits);
        $debits = LoanService::toCentavos((float) $row->debits);

        return [
            'member_count' => (int) $row->member_count,
            'total_credits' => $credits / 100,
            'total_debits' => $debits / 100,
            'balance' => ($credits - $debits) / 100,
        ];
    }

    private function pesos(mixed $amount): float|int
    {
        return LoanService::toCentavos((float) $amount) / 100;
    }
}
