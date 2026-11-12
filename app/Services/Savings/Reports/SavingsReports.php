<?php

namespace App\Services\Savings\Reports;

use App\Http\Resources\Savings\SavingsUserRef;
use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsSetting;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Services\Savings\AccruedInterest;
use App\Services\Savings\AvailableBalance;
use App\Services\Savings\Dashboard\DepositFigures;
use App\Services\Savings\SavingsMath;
use App\Services\Savings\TimeDeposits\Settlement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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

    /** The rows the large-transaction alert is sent for (LargeTransactionAlert). */
    public const LARGE_TRANSACTION_TYPES = ['deposit', 'withdrawal', 'closing'];

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
     * A period from the filters (review L3):
     * - neither end: the first of this month to today, or to this month's end
     *   when `$wholeMonth`;
     * - only a start: to the end of the start's month when `$wholeMonth` or
     *   when the start is after today, otherwise to today;
     * - only an end: from the first of the end's month.
     *
     * `$wholeMonth` is the time deposit maturities report's rule.
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
     * `listed_count` is every placement the report lists. Every other total
     * counts only the placements still owed: active, or matured and not
     * settled yet (Settlement::OPEN_STATUSES). A placement already paid out,
     * rolled over or pre-terminated is listed with its status but counted in
     * no money total.
     *
     * `payout_amount` is the cash the placements leave the cooperative with
     * at maturity: everything on `payout`, the net interest on
     * `rollover_principal`, nothing on `rollover_all`.
     *
     * @return array<string, int|string>
     */
    public function maturityTotals(array $filters): array
    {
        $owed = 'status IN ('.implode(', ', array_fill(0, count(Settlement::OPEN_STATUSES), '?')).')';
        $bindings = Settlement::OPEN_STATUSES;

        $sums = $this->maturitiesQuery($filters)->reorder()->toBase()
            ->selectRaw("COUNT(*) AS listed_count, COALESCE(SUM(CASE WHEN {$owed} THEN 1 ELSE 0 END), 0) AS rows_count", $bindings)
            ->selectRaw("COALESCE(SUM(CASE WHEN {$owed} THEN principal ELSE 0 END), 0) AS principal", $bindings)
            ->selectRaw("COALESCE(SUM(CASE WHEN {$owed} THEN net_interest ELSE 0 END), 0) AS net_interest", $bindings)
            ->selectRaw("COALESCE(SUM(CASE WHEN {$owed} THEN maturity_amount ELSE 0 END), 0) AS maturity_amount", $bindings)
            ->selectRaw("COALESCE(SUM(CASE WHEN NOT ({$owed}) THEN 0 WHEN maturity_instruction = 'payout' THEN maturity_amount WHEN maturity_instruction = 'rollover_principal' THEN net_interest ELSE 0 END), 0) AS payout", $bindings)
            ->first();

        return [
            'listed_count' => (int) $sums->listed_count,
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

    // ── Account listing (gap round S5) ──

    /** @return Builder<SavingsAccount> every account in the scope, closed ones too */
    public function accountListQuery(array $filters): Builder
    {
        return $this->accounts($filters)->with('accountOfficer')->orderBy('account_number')->orderBy('id');
    }

    /** @return array<string, mixed> */
    public function accountListRow(SavingsAccount $account): array
    {
        return [
            ...$this->accountFields($account),
            'balance' => $this->money($account->ledger_balance),
            'opened_on' => $account->opened_on->toDateString(),
            'closed_on' => $account->closed_on?->toDateString(),
            'dormant_since' => $account->dormant_since?->toDateString(),
            'last_member_activity_on' => $account->last_member_activity_on?->toDateString(),
            'account_officer' => SavingsUserRef::from($account->accountOfficer),
        ];
    }

    /** @return array<string, int|string> */
    public function accountListTotals(array $filters): array
    {
        $query = $this->accountListQuery($filters)->reorder()->toBase()
            ->selectRaw('COUNT(*) AS rows_count, COALESCE(SUM(ledger_balance), 0) AS balance');

        foreach (SavingsAccount::STATUSES as $status) {
            $query->selectRaw("COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS {$status}_count", [$status]);
        }

        $sums = $query->first();

        return [
            'account_count' => (int) $sums->rows_count,
            ...collect(SavingsAccount::STATUSES)->mapWithKeys(fn (string $status) => ["{$status}_count" => (int) $sums->{"{$status}_count"}])->all(),
            'total_balance' => $this->money($sums->balance),
        ];
    }

    // ── Deposits, withdrawals and large transactions (gap round S5) ──

    /**
     * Deposits dated in the period (DepositFigures::DEPOSIT_TYPES), money
     * moved from a time deposit left out.
     *
     * @return Builder<SavingsTransaction>
     */
    public function depositsQuery(array $filters): Builder
    {
        return $this->transactionsQuery($filters)->with('reversedBy')
            ->whereIn('type', DepositFigures::DEPOSIT_TYPES)
            ->whereNull('time_deposit_id');
    }

    /**
     * Withdrawals and closing payouts dated in the period
     * (DepositFigures::WITHDRAWAL_TYPES), money moved into a time deposit
     * left out.
     *
     * @return Builder<SavingsTransaction>
     */
    public function withdrawalsQuery(array $filters): Builder
    {
        return $this->transactionsQuery($filters)->with('reversedBy')
            ->whereIn('type', DepositFigures::WITHDRAWAL_TYPES)
            ->whereNull('time_deposit_id');
    }

    /** The large-transaction alert's amount, in centavos (savings settings). */
    public function largeTransactionThreshold(): int
    {
        return SavingsMath::centavos(SavingsSetting::current()->large_transaction_alert);
    }

    /**
     * Deposits, withdrawals and closing payouts dated in the period of the
     * large-transaction alert's amount or more (the rows the alert is sent
     * for, LargeTransactionAlert), money moved to or from a time deposit left
     * out, as the deposit and withdrawal reports do.
     *
     * @return Builder<SavingsTransaction>
     */
    public function largeTransactionsQuery(array $filters): Builder
    {
        $threshold = SavingsMath::pesos($this->largeTransactionThreshold());

        return $this->transactionsQuery($filters)->with('reversedBy')
            ->whereIn('type', self::LARGE_TRANSACTION_TYPES)
            ->whereNull('time_deposit_id')
            ->where(fn ($q) => $q->where('credit', '>=', $threshold)->orWhere('debit', '>=', $threshold));
    }

    /**
     * A deposit, withdrawal or large transaction: the register's fields, its
     * amount, and whether it was reversed by the period's end `$asAt`
     * (listed, counted in no total). A reversal dated later leaves `reversed`
     * false, so the period reads as it stood at its end, and still shows its
     * reference and date.
     *
     * @return array<string, mixed>
     */
    public function flowRow(SavingsTransaction $row, string $asAt): array
    {
        return [
            ...$this->transactionRow($row),
            'amount' => SavingsMath::pesos(max(SavingsMath::centavos($row->credit), SavingsMath::centavos($row->debit))),
            'reversed' => $row->reversedBy !== null && $row->reversedBy->date->toDateString() <= $asAt,
            'reversed_by_reference' => $row->reversedBy?->reference,
            'reversed_on' => $row->reversedBy?->date->toDateString(),
        ];
    }

    /**
     * Totals over the whole filter: every row listed (`count`), the ones
     * reversed by the period's end (`reversed_count`), and the money of the
     * rest, in (`total_credit`) and out (`total_debit`) and both
     * (`total_amount`).
     *
     * @param  Builder<SavingsTransaction>  $query
     * @return array<string, int|string>
     */
    public function flowTotals(Builder $query, string $asAt): array
    {
        $sums = DB::query()
            ->fromSub($query->reorder()->toBase()->select(['savings_transactions.id', 'savings_transactions.credit', 'savings_transactions.debit']), 'f')
            ->leftJoin('savings_transactions AS r', fn ($join) => $join->on('r.reverses_transaction_id', '=', 'f.id')->where('r.date', '<=', $asAt))
            ->selectRaw('COUNT(*) AS rows_count, COALESCE(SUM(CASE WHEN r.id IS NULL THEN 0 ELSE 1 END), 0) AS reversed_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN r.id IS NULL THEN f.credit ELSE 0 END), 0) AS credits')
            ->selectRaw('COALESCE(SUM(CASE WHEN r.id IS NULL THEN f.debit ELSE 0 END), 0) AS debits')
            ->first();

        $credits = SavingsMath::centavos($sums->credits);
        $debits = SavingsMath::centavos($sums->debits);

        return [
            'count' => (int) $sums->rows_count,
            'reversed_count' => (int) $sums->reversed_count,
            'total_credit' => SavingsMath::pesos($credits),
            'total_debit' => SavingsMath::pesos($debits),
            'total_amount' => SavingsMath::pesos($credits + $debits),
        ];
    }

    // ── Time deposits outstanding (gap round S5) ──

    /**
     * Time deposits outstanding at the end of `as_of` (default today): placed
     * by then and not settled by then, the GL control's rule for 2060.
     *
     * @return Builder<TimeDeposit>
     */
    public function timeDepositsQuery(array $filters): Builder
    {
        $asOf = $this->asOf($filters);

        return $this->scopeBranches(TimeDeposit::query(), $filters)
            ->with(['borrower', 'branch', 'product'])
            ->where('placement_date', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('settled_on')->orWhere('settled_on', '>', $asOf))
            ->orderBy('maturity_date')
            ->orderBy('id');
    }

    /** @return array<string, mixed> */
    public function timeDepositRow(TimeDeposit $deposit, array $filters): array
    {
        return [
            ...$this->maturityRow($deposit),
            'days_to_maturity' => (int) CarbonImmutable::parse($this->asOf($filters))->diffInDays($deposit->maturity_date, false),
        ];
    }

    /** @return array<string, int|string> */
    public function timeDepositTotals(array $filters): array
    {
        $sums = $this->timeDepositsQuery($filters)->reorder()->toBase()
            ->selectRaw('COUNT(*) AS rows_count, COALESCE(SUM(principal), 0) AS principal, COALESCE(SUM(net_interest), 0) AS net_interest, COALESCE(SUM(maturity_amount), 0) AS maturity_amount')
            ->first();

        return [
            'count' => (int) $sums->rows_count,
            'total_principal' => $this->money($sums->principal),
            'total_net_interest' => $this->money($sums->net_interest),
            'total_maturity_amount' => $this->money($sums->maturity_amount),
        ];
    }

    // ── Interest accrual (gap round S5) ──

    /**
     * Accounts not closed today, for the interest earned and not yet credited
     * (AccruedInterest, today only: what was earned on a past day is not kept).
     *
     * @return Builder<SavingsAccount>
     */
    public function interestAccrualQuery(array $filters): Builder
    {
        return $this->accounts($filters)
            ->where('status', '!=', SavingsAccount::STATUS_CLOSED)
            ->orderBy('account_number')
            ->orderBy('id');
    }

    /**
     * @param  Collection<int, SavingsAccount>  $accounts
     * @return list<array<string, mixed>>
     */
    public function interestAccrualRows(Collection $accounts): array
    {
        $accrued = $accounts->isEmpty() ? [] : $this->accrued->forMany($accounts);

        return $accounts->map(fn (SavingsAccount $account) => [
            ...$this->accountFields($account),
            'balance' => $this->money($account->ledger_balance),
            'interest_credited_through' => $account->interest_credited_through?->toDateString(),
            'accrual_from' => $accrued[$account->id]['from'],
            'accrual_to' => $accrued[$account->id]['to'],
            'days' => $accrued[$account->id]['days'],
            'average_daily_balance' => SavingsMath::pesos($accrued[$account->id]['average_daily_balance']),
            'gross_interest' => SavingsMath::pesos($accrued[$account->id]['gross']),
            'tax' => SavingsMath::pesos($accrued[$account->id]['tax']),
            'net_interest' => SavingsMath::pesos($accrued[$account->id]['net']),
            'skip_reason' => $accrued[$account->id]['skip_reason'],
        ])->values()->all();
    }

    /** @return array<string, int|string> */
    public function interestAccrualTotals(array $filters): array
    {
        $totals = ['count' => 0, 'gross' => 0, 'tax' => 0, 'net' => 0];

        $this->interestAccrualQuery($filters)->setEagerLoads([])->reorder()->chunkById(self::CHUNK, function (Collection $accounts) use (&$totals): void {
            $totals['count'] += $accounts->count();

            foreach ($this->accrued->forMany($accounts) as $figures) {
                $totals['gross'] += $figures['gross'];
                $totals['tax'] += $figures['tax'];
                $totals['net'] += $figures['net'];
            }
        });

        return [
            'account_count' => $totals['count'],
            'total_gross_interest' => SavingsMath::pesos($totals['gross']),
            'total_tax' => SavingsMath::pesos($totals['tax']),
            'total_net_interest' => SavingsMath::pesos($totals['net']),
        ];
    }

    // ── Account activity (gap round S5) ──

    /**
     * Accounts open at some point in the period (opened by its end, and not
     * closed before its start).
     *
     * @return Builder<SavingsAccount>
     */
    public function accountActivityQuery(array $filters): Builder
    {
        [$from, $to] = $this->period($filters);

        return $this->accounts($filters)
            ->where('opened_on', '<=', $to)
            ->where(fn ($q) => $q->where('status', '!=', SavingsAccount::STATUS_CLOSED)->orWhere('closed_on', '>=', $from))
            ->orderBy('account_number')
            ->orderBy('id');
    }

    /**
     * Each account's balance before the period, money in and out in it (every
     * row, reversals too, so opening + in − out = closing), its rows and its
     * last row's date.
     *
     * @param  Collection<int, SavingsAccount>  $accounts
     * @return list<array<string, mixed>>
     */
    public function accountActivityRows(Collection $accounts, array $filters): array
    {
        $figures = $this->activityFigures(DB::table('savings_transactions')->whereIn('account_id', $accounts->pluck('id')->all()), $filters)
            ->groupBy('account_id')
            ->addSelect('account_id')
            ->selectRaw('MAX(CASE WHEN date BETWEEN ? AND ? THEN date END) AS last_date', $this->period($filters))
            ->get()
            ->keyBy('account_id');

        return $accounts->map(function (SavingsAccount $account) use ($figures) {
            $row = $figures[$account->id] ?? null;
            [$opening, $credits, $debits, $count] = $this->activityLine($row);

            return [
                ...$this->accountFields($account),
                'opened_on' => $account->opened_on->toDateString(),
                'closed_on' => $account->closed_on?->toDateString(),
                'opening_balance' => SavingsMath::pesos($opening),
                'credits' => SavingsMath::pesos($credits),
                'debits' => SavingsMath::pesos($debits),
                'transaction_count' => $count,
                'closing_balance' => SavingsMath::pesos($opening + $credits - $debits),
                'last_transaction_on' => $row?->last_date === null ? null : substr((string) $row->last_date, 0, 10),
            ];
        })->values()->all();
    }

    /** @return array<string, int|string> */
    public function accountActivityTotals(array $filters): array
    {
        $ids = $this->accountActivityQuery($filters)->setEagerLoads([])->reorder()->toBase()->select('savings_accounts.id');
        $row = $this->activityFigures(DB::table('savings_transactions')->whereIn('account_id', $ids), $filters)->first();
        [$opening, $credits, $debits, $count] = $this->activityLine($row);

        return [
            'account_count' => $this->accountActivityQuery($filters)->setEagerLoads([])->reorder()->count(),
            'transaction_count' => $count,
            'total_opening_balance' => SavingsMath::pesos($opening),
            'total_credits' => SavingsMath::pesos($credits),
            'total_debits' => SavingsMath::pesos($debits),
            'total_closing_balance' => SavingsMath::pesos($opening + $credits - $debits),
        ];
    }

    private function activityFigures(QueryBuilder $query, array $filters): QueryBuilder
    {
        [$from, $to] = $this->period($filters);

        return $query
            ->where('date', '<=', $to)
            ->selectRaw('COALESCE(SUM(CASE WHEN date < ? THEN credit - debit ELSE 0 END), 0) AS opening', [$from])
            ->selectRaw('COALESCE(SUM(CASE WHEN date >= ? THEN credit ELSE 0 END), 0) AS credits', [$from])
            ->selectRaw('COALESCE(SUM(CASE WHEN date >= ? THEN debit ELSE 0 END), 0) AS debits', [$from])
            ->selectRaw('COALESCE(SUM(CASE WHEN date >= ? THEN 1 ELSE 0 END), 0) AS rows_count', [$from]);
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} opening, credits, debits, rows */
    private function activityLine(?object $row): array
    {
        if ($row === null) {
            return [0, 0, 0, 0];
        }

        return [SavingsMath::signedCentavos($row->opening), SavingsMath::centavos($row->credits), SavingsMath::centavos($row->debits), (int) $row->rows_count];
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
     * Narrow a query on a table with `branch_id` and `product_id` to the
     * caller's branches, the `branch_id` filter and the `product_id` filter.
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
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where($column, $id))
            ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where($query->getModel()->qualifyColumn('product_id'), $id));
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
