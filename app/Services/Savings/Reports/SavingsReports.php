<?php

namespace App\Services\Savings\Reports;

use App\Http\Resources\Savings\SavingsUserRef;
use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Services\Savings\AccruedInterest;
use App\Services\Savings\AvailableBalance;
use App\Services\Savings\SavingsMath;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The savings list reports (design §2): balances, the transaction register,
 * time deposit maturities, interest postings and dormant accounts.
 *
 * Each report is a query over the caller's branches (`branch_ids`, null for
 * every branch) and its filters, a row shape (the frontend's report builders'
 * fields), and totals worked out over the WHOLE filter, never one page.
 * Money is computed in centavos and answered as two-decimal strings. The
 * controller pages the query for the screen and streams it for the CSV.
 */
class SavingsReports
{
    private const CHUNK = 500;

    public function __construct(
        private readonly AvailableBalance $available,
        private readonly AccruedInterest $accrued,
    ) {}

    // ── Balances ──

    /** The day a balances report reads: `as_of`, else today. */
    public function asOf(array $filters): string
    {
        return $filters['as_of'] ?? now()->toDateString();
    }

    /** @return Builder<SavingsAccount> */
    public function balancesQuery(array $filters): Builder
    {
        $asOf = $this->asOf($filters);

        return $this->accounts($filters)
            ->where('opened_on', '<=', $asOf)
            ->where(fn ($q) => $q->where('status', '!=', SavingsAccount::STATUS_CLOSED)->orWhere('closed_on', '>', $asOf))
            ->orderBy('account_number')
            ->orderBy('id');
    }

    /**
     * Rows for a page (or chunk) of accounts. Holds, pending approvals, the
     * available balance and accrued interest are today's figures, so a past
     * `as_of` reads them as null.
     *
     * @param  Collection<int, SavingsAccount>  $accounts
     * @return list<array<string, mixed>>
     */
    public function balanceRows(Collection $accounts, array $filters): array
    {
        $asOf = $this->asOf($filters);
        $today = $asOf === now()->toDateString();
        $balances = $this->balancesOn($accounts, $asOf);
        $breakdown = $today ? $this->available->forMany($accounts) : [];
        $accrued = $today && $accounts->isNotEmpty() ? $this->accrued->forMany($accounts) : [];

        return $accounts->map(fn (SavingsAccount $account) => [
            ...$this->accountFields($account),
            'balance' => SavingsMath::pesos($balances[$account->id] ?? 0),
            'hold_amount' => $today ? SavingsMath::pesos($breakdown[$account->id]['holds']) : null,
            'pending_amount' => $today ? SavingsMath::pesos($breakdown[$account->id]['pending']) : null,
            'available_balance' => $today ? SavingsMath::pesos($breakdown[$account->id]['available']) : null,
            'accrued_interest' => $today ? SavingsMath::pesos($accrued[$account->id]['gross'] ?? 0) : null,
            'interest_credited_through' => $account->interest_credited_through?->toDateString(),
            'last_member_activity_on' => $account->last_member_activity_on?->toDateString(),
            'opened_on' => $account->opened_on->toDateString(),
        ])->values()->all();
    }

    /** @return array<string, int|string|null> */
    public function balanceTotals(array $filters): array
    {
        $asOf = $this->asOf($filters);
        $today = $asOf === now()->toDateString();
        $totals = ['count' => 0, 'balance' => 0, 'holds' => 0, 'pending' => 0, 'available' => 0, 'accrued' => 0];

        $this->balancesQuery($filters)->setEagerLoads([])->reorder()->chunkById(self::CHUNK, function (Collection $accounts) use (&$totals, $asOf, $today): void {
            $totals['count'] += $accounts->count();
            $totals['balance'] += array_sum($this->balancesOn($accounts, $asOf));

            if (! $today) {
                return;
            }

            foreach ($this->available->forMany($accounts) as $figures) {
                $totals['holds'] += $figures['holds'];
                $totals['pending'] += $figures['pending'];
                $totals['available'] += $figures['available'];
            }

            foreach ($this->accrued->forMany($accounts) as $figures) {
                $totals['accrued'] += $figures['gross'];
            }
        });

        return [
            'account_count' => $totals['count'],
            'total_balance' => SavingsMath::pesos($totals['balance']),
            'total_hold' => $today ? SavingsMath::pesos($totals['holds']) : null,
            'total_pending' => $today ? SavingsMath::pesos($totals['pending']) : null,
            'total_available' => $today ? SavingsMath::pesos($totals['available']) : null,
            'total_accrued_interest' => $today ? SavingsMath::pesos($totals['accrued']) : null,
        ];
    }

    // ── Transactions ──

    /** @return array{0: string, 1: string} the report's period */
    public function period(array $filters, bool $wholeMonth = false): array
    {
        return self::resolvePeriod($filters['date_from'] ?? null, $filters['date_to'] ?? null, $wholeMonth);
    }

    /**
     * A period from the filters (review L3). Neither end: this month, to today
     * (`$wholeMonth`: to the month's end). Only a start: to today, or to the
     * end of the start's month when that is later or `$wholeMonth`. Only an
     * end: from the first of the end's month.
     *
     * @return array{0: string, 1: string}
     */
    public static function resolvePeriod(?string $from, ?string $to, bool $wholeMonth = false): array
    {
        $today = CarbonImmutable::parse(now()->toDateString());

        if ($from === null && $to === null) {
            return [$today->startOfMonth()->toDateString(), ($wholeMonth ? $today->endOfMonth() : $today)->toDateString()];
        }

        if ($to === null) {
            $start = CarbonImmutable::parse($from);
            $end = $wholeMonth || $start->greaterThan($today) ? $start->endOfMonth() : $today;

            return [$start->toDateString(), $end->toDateString()];
        }

        $end = CarbonImmutable::parse($to);

        return [$from ?? $end->startOfMonth()->toDateString(), $end->toDateString()];
    }

    /** @return Builder<SavingsTransaction> */
    public function transactionsQuery(array $filters): Builder
    {
        [$from, $to] = $this->period($filters);

        return SavingsTransaction::query()
            ->with(['account.borrower', 'account.branch', 'account.product', 'creator'])
            ->whereHas('account', fn ($q) => $this->scopeBranches($q, $filters))
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->orderBy('id');
    }

    /** @return array<string, mixed> */
    public function transactionRow(SavingsTransaction $row): array
    {
        return [
            'id' => $row->id,
            'date' => $row->date->toDateString(),
            'value_date' => $row->value_date->toDateString(),
            'reference' => $row->reference,
            'type' => $row->type,
            'method' => $row->method,
            'external_reference' => $row->external_reference,
            'credit' => $this->money($row->credit),
            'debit' => $this->money($row->debit),
            'balance_after' => $this->money($row->balance_after),
            'reverses_transaction_id' => $row->reverses_transaction_id,
            'created_by' => SavingsUserRef::from($row->creator),
            ...$this->accountFields($row->account),
            'account_id' => $row->account_id,
        ];
    }

    /** @return array<string, int|string> */
    public function transactionTotals(array $filters): array
    {
        $sums = $this->transactionsQuery($filters)->reorder()->toBase()
            ->selectRaw('COUNT(*) AS rows_count, COALESCE(SUM(credit), 0) AS credits, COALESCE(SUM(debit), 0) AS debits')
            ->first();

        return [
            'count' => (int) $sums->rows_count,
            'total_credit' => $this->money($sums->credits),
            'total_debit' => $this->money($sums->debits),
        ];
    }

    // ── Time deposit maturities ──

    /** @return Builder<TimeDeposit> */
    public function maturitiesQuery(array $filters): Builder
    {
        [$from, $to] = $this->period($filters, true);

        return $this->scopeBranches(TimeDeposit::query(), $filters)
            ->with(['borrower', 'branch', 'product'])
            ->whereBetween('maturity_date', [$from, $to])
            ->where('status', '!=', 'preterminated')
            ->orderBy('maturity_date')
            ->orderBy('id');
    }

    /** @return array<string, mixed> */
    public function maturityRow(TimeDeposit $deposit): array
    {
        return [
            'id' => $deposit->id,
            'certificate_number' => $deposit->certificate_number,
            'borrower_id' => $deposit->borrower_id,
            'borrower_name' => $deposit->borrower?->full_name,
            'borrower_code' => $deposit->borrower?->borrower_code,
            'product_id' => $deposit->product_id,
            'product_name' => $deposit->product?->name,
            'branch_id' => $deposit->branch_id,
            'branch_name' => $deposit->branch?->name,
            'principal' => $this->money($deposit->principal),
            'annual_rate' => SavingsMath::rateString(SavingsMath::rateUnits((string) $deposit->annual_rate)),
            'term_days' => (int) $deposit->term_days,
            'placement_date' => $deposit->placement_date->toDateString(),
            'maturity_date' => $deposit->maturity_date->toDateString(),
            'gross_interest' => $this->money($deposit->gross_interest),
            'withholding_tax' => $this->money($deposit->withholding_tax),
            'net_interest' => $this->money($deposit->net_interest),
            'maturity_amount' => $this->money($deposit->maturity_amount),
            'maturity_instruction' => $deposit->maturity_instruction,
            'status' => $deposit->status,
        ];
    }

    /**
     * `payout_amount` is the cash the placements leave the cooperative with
     * at maturity: everything on `payout`, the net interest on
     * `rollover_principal`, nothing on `rollover_all`.
     *
     * @return array<string, int|string>
     */
    public function maturityTotals(array $filters): array
    {
        $sums = $this->maturitiesQuery($filters)->reorder()->toBase()
            ->selectRaw('COUNT(*) AS rows_count, COALESCE(SUM(principal), 0) AS principal, COALESCE(SUM(net_interest), 0) AS net_interest, COALESCE(SUM(maturity_amount), 0) AS maturity_amount')
            ->selectRaw("COALESCE(SUM(CASE WHEN maturity_instruction = 'payout' THEN maturity_amount WHEN maturity_instruction = 'rollover_principal' THEN net_interest ELSE 0 END), 0) AS payout")
            ->first();

        return [
            'count' => (int) $sums->rows_count,
            'total_principal' => $this->money($sums->principal),
            'total_net_interest' => $this->money($sums->net_interest),
            'total_maturity_amount' => $this->money($sums->maturity_amount),
            'payout_amount' => $this->money($sums->payout),
        ];
    }

    // ── Interest postings ──

    /** @return Builder<SavingsInterestRun> */
    public function interestPostingsQuery(array $filters): Builder
    {
        [$from, $to] = $this->period($filters);

        return $this->scopeBranches(SavingsInterestRun::query(), $filters)
            ->with(['product', 'branch', 'poster', 'reverser'])
            ->whereIn('status', ['posted', 'reversed'])
            ->where('posted_at', '>=', $from.' 00:00:00')
            ->where('posted_at', '<=', $to.' 23:59:59')
            ->orderBy('posted_at')
            ->orderBy('id');
    }

    /** @return array<string, mixed> */
    public function interestPostingRow(SavingsInterestRun $run): array
    {
        return [
            'id' => $run->id,
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
            'product_id' => $run->product_id,
            'product_name' => $run->product?->name,
            'branch_id' => $run->branch_id,
            'branch_name' => $run->branch?->name,
            'status' => $run->status,
            'account_count' => (int) $run->account_count,
            'total_gross' => $this->money($run->total_gross),
            'total_tax' => $this->money($run->total_tax),
            'total_net' => $this->money($run->total_net),
            'posted_at' => $run->posted_at?->toJSON(),
            'posted_by' => SavingsUserRef::from($run->poster),
            'reversed_at' => $run->reversed_at?->toJSON(),
            'reversed_by' => SavingsUserRef::from($run->reverser),
        ];
    }

    /**
     * Every run posted in the period is listed, reversed ones too (review
     * L1), but the money totals are the runs still posted: a reversed run's
     * interest was taken back.
     *
     * @return array<string, int|string>
     */
    public function interestPostingTotals(array $filters): array
    {
        $sums = $this->interestPostingsQuery($filters)->setEagerLoads([])->reorder()->toBase()
            ->selectRaw("COUNT(*) AS rows_count, COALESCE(SUM(CASE WHEN status = 'reversed' THEN 1 ELSE 0 END), 0) AS reversed_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'posted' THEN total_gross ELSE 0 END), 0) AS gross")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'posted' THEN total_tax ELSE 0 END), 0) AS tax")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'posted' THEN total_net ELSE 0 END), 0) AS net")
            ->first();

        return [
            'count' => (int) $sums->rows_count,
            'reversed_count' => (int) $sums->reversed_count,
            'total_gross' => $this->money($sums->gross),
            'total_tax' => $this->money($sums->tax),
            'total_net' => $this->money($sums->net),
        ];
    }

    // ── Dormant accounts ──

    /** @return Builder<SavingsAccount> */
    public function dormantQuery(array $filters): Builder
    {
        return $this->accounts($filters)
            ->where('status', SavingsAccount::STATUS_DORMANT)
            ->orderBy('dormant_since')
            ->orderBy('id');
    }

    /** @return array<string, mixed> */
    public function dormantRow(SavingsAccount $account): array
    {
        return [
            ...$this->accountFields($account),
            'balance' => $this->money($account->ledger_balance),
            'last_member_activity_on' => $account->last_member_activity_on?->toDateString(),
            'dormant_since' => $account->dormant_since?->toDateString(),
        ];
    }

    /** @return array<string, int|string> */
    public function dormantTotals(array $filters): array
    {
        $sums = $this->dormantQuery($filters)->reorder()->toBase()
            ->selectRaw('COUNT(*) AS rows_count, COALESCE(SUM(ledger_balance), 0) AS balance')
            ->first();

        return [
            'account_count' => (int) $sums->rows_count,
            'total_balance' => $this->money($sums->balance),
        ];
    }

    // ── Shared ──

    /**
     * The account fields every savings report row carries.
     *
     * @return array<string, mixed>
     */
    public function accountFields(?SavingsAccount $account): array
    {
        return [
            'account_id' => $account?->id,
            'account_number' => $account?->account_number,
            'borrower_id' => $account?->borrower_id,
            'borrower_name' => $account?->borrower?->full_name,
            'borrower_code' => $account?->borrower?->borrower_code,
            'product_id' => $account?->product_id,
            'product_name' => $account?->product?->name,
            'branch_id' => $account?->branch_id,
            'branch_name' => $account?->branch?->name,
            'status' => $account?->status,
        ];
    }

    /** @return Builder<SavingsAccount> */
    private function accounts(array $filters): Builder
    {
        return $this->scopeBranches(SavingsAccount::query(), $filters)->with(['borrower', 'branch', 'product']);
    }

    /**
     * Narrow a query on a table with `branch_id` to the caller's branches and
     * the `branch_id` filter.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    private function scopeBranches(Builder $query, array $filters): Builder
    {
        $column = $query->getModel()->qualifyColumn('branch_id');

        return $query
            ->when($filters['branch_ids'] ?? null, fn ($q, array $ids) => $q->whereIn($column, $ids))
            ->when(array_key_exists('branch_ids', $filters) && $filters['branch_ids'] === [], fn ($q) => $q->whereRaw('1 = 0'))
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where($column, $id));
    }

    /**
     * Each account's balance at the end of `$asOf`, by business date, in
     * centavos (the cached ledger balance when that is today).
     *
     * @param  Collection<int, SavingsAccount>  $accounts
     * @return array<int, int>
     */
    private function balancesOn(Collection $accounts, string $asOf): array
    {
        if ($asOf === now()->toDateString()) {
            return $accounts->mapWithKeys(fn (SavingsAccount $account) => [$account->id => SavingsMath::signedCentavos($account->ledger_balance)])->all();
        }

        $sums = DB::table('savings_transactions')
            ->whereIn('account_id', $accounts->pluck('id')->all())
            ->where('date', '<=', $asOf)
            ->groupBy('account_id')
            ->selectRaw('account_id, COALESCE(SUM(credit), 0) AS credits, COALESCE(SUM(debit), 0) AS debits')
            ->get()
            ->keyBy('account_id');

        return $accounts->mapWithKeys(fn (SavingsAccount $account) => [$account->id => isset($sums[$account->id])
            ? SavingsMath::centavos($sums[$account->id]->credits) - SavingsMath::centavos($sums[$account->id]->debits)
            : 0,
        ])->all();
    }

    private function money(mixed $pesos): string
    {
        return SavingsMath::pesos(SavingsMath::signedCentavos($pesos));
    }
}
