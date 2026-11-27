<?php

namespace App\Services\Portal\Read;

use App\Models\SavingsAccount;
use App\Models\SavingsHold;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Services\LikePattern;
use App\Services\Portal\MemberScope;
use App\Services\Savings\AccruedInterest;
use App\Services\Savings\AvailableBalance;
use App\Services\Savings\RateBook;
use App\Services\Savings\Reports\StatementBuilder;
use App\Services\Savings\TimeDeposits\Settlement;
use App\Services\Savings\TimeDeposits\TimeDepositPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A member's savings accounts and time deposits (portal design §0 finding
 * 10, §3). Every figure is the savings module's own: the balances and the
 * available balance from AvailableBalance, accrued interest from
 * AccruedInterest, the rate from RateBook (the tier the ledger balance
 * reaches today, with the ladder in `rate_tiers`), the passbook's running balance
 * from the rows, and a time deposit's figures through TimeDepositPresenter.
 *
 * - `held` and `pending_withdrawal`: AvailableBalance's split of what it takes
 *   off (forManyWithRequests): the member's own withdrawal requests read as
 *   pending withdrawals, beside outflows waiting for a checker.
 * - The passbook's opening balance is the savings statement's
 *   (StatementBuilder::openingBalance).
 * - `withdrawable`: the available balance on an active account, which
 *   already keeps the maintaining balance (never taken off twice); "0.00" on
 *   a dormant, frozen or closed one, where the member must visit the branch.
 */
final class SavingsReader
{
    /** A time deposit this many days or fewer from maturity is "maturing soon". */
    public const MATURING_SOON_DAYS = 30;

    public function __construct(
        private readonly MemberScope $scope,
        private readonly AvailableBalance $available,
        private readonly AccruedInterest $accrued,
        private readonly RateBook $rates,
        private readonly TimeDepositPresenter $timeDeposits,
        private readonly StatementBuilder $statements,
    ) {}

    /** @return Builder<SavingsAccount> */
    public function accounts(): Builder
    {
        return SavingsAccount::query()->where('borrower_id', $this->scope->borrowerId());
    }

    public function findAccount(int $id): SavingsAccount
    {
        return $this->accounts()->findOrFail($id);
    }

    /** @return Builder<TimeDeposit> */
    public function timeDepositQuery(): Builder
    {
        return TimeDeposit::query()->where('borrower_id', $this->scope->borrowerId());
    }

    public function findTimeDeposit(int $id): TimeDeposit
    {
        return $this->timeDepositQuery()->findOrFail($id);
    }

    /** @return list<array<string, mixed>> open accounts first, then by account number */
    public function list(): array
    {
        $accounts = $this->accounts()->get()
            ->sortBy(fn (SavingsAccount $a) => ($a->isClosed() ? '1' : '0').$a->account_number)
            ->values();

        return $this->present($accounts);
    }

    /** @return array<string, mixed> */
    public function account(SavingsAccount $account): array
    {
        return $this->present(collect([$account]))[0];
    }

    /**
     * The figures for many accounts in a fixed number of queries: the
     * breakdown, accrued interest, member-request holds, last transaction
     * days, and one rate read per product.
     *
     * @param  Collection<int, SavingsAccount>  $accounts
     * @return list<array<string, mixed>>
     */
    public function present(Collection $accounts): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        $accounts = new EloquentCollection($accounts->all());
        $accounts->loadMissing(['product:id,name', 'branch:id,name']);
        $ids = $accounts->pluck('id')->all();
        $today = now()->toDateString();

        $breakdowns = $this->available->forManyWithRequests($accounts, [SavingsHold::TYPE_MEMBER_REQUEST]);
        $open = $accounts->reject(fn (SavingsAccount $a) => $a->isClosed())->values();
        $accrued = $open->isEmpty() ? [] : $this->accrued->forMany($open);
        $lastDays = DB::table('savings_transactions')->whereIn('account_id', $ids)->groupBy('account_id')
            ->selectRaw('account_id, MAX(date) AS last_day')->pluck('last_day', 'account_id');
        $ladders = [];

        return $accounts->map(function (SavingsAccount $a) use ($breakdowns, $accrued, $lastDays, $today, &$ladders): array {
            $b = $breakdowns[$a->id];
            $available = $a->isClosed() ? 0 : $b['available'];
            $ladders[$a->product_id] ??= $this->rates->ladderOn($a->product_id, null, $today);
            $ladder = $ladders[$a->product_id];

            return [
                'id' => $a->id,
                'account_number' => $a->account_number,
                'product_name' => $a->product?->name,
                'status' => $a->status,
                'ledger_balance' => Pesos::fromCentavos($b['ledger']),
                'held' => Pesos::fromCentavos($b['held']),
                'pending_withdrawal' => Pesos::fromCentavos($b['pending_withdrawal']),
                'maintaining_balance' => Pesos::fromCentavos($b['maintaining']),
                'available_balance' => Pesos::fromCentavos($available),
                'withdrawable' => Pesos::fromCentavos($a->status === SavingsAccount::STATUS_ACTIVE ? $available : 0),
                'annual_rate' => RateBook::tierFor($ladder, $b['ledger'])['annual_rate'] ?? null,
                'rate_tiers' => array_map(fn (array $tier) => ['min_balance' => $tier['min_balance'], 'annual_rate' => $tier['annual_rate']], $ladder),
                'accrued_interest' => Pesos::fromCentavos($accrued[$a->id]['gross'] ?? 0),
                'interest_credited_through' => $a->interest_credited_through?->toDateString(),
                'opened_on' => $a->opened_on?->toDateString(),
                'branch_name' => $a->branch?->name,
                'last_transaction_on' => isset($lastDays[$a->id]) ? CarbonImmutable::parse($lastDays[$a->id])->toDateString() : null,
            ];
        })->values()->all();
    }

    /**
     * The passbook: one account's rows newest first, filtered by business
     * date, type and reference, with each row's stored running balance.
     *
     * @param  array{from?: string|null, to?: string|null, type?: string|null, search?: string|null}  $filters
     * @return array{page: LengthAwarePaginator<int, SavingsTransaction>, opening_balance: string}
     */
    public function passbook(SavingsAccount $account, array $filters, int $perPage): array
    {
        $base = fn (): Builder => SavingsTransaction::query()->where('account_id', $account->id);
        $from = $filters['from'] ?? null;

        $page = $base()
            ->when($from !== null, fn ($q) => $q->where('date', '>=', $from))
            ->when(($filters['to'] ?? null) !== null, fn ($q) => $q->where('date', '<=', $filters['to']))
            ->when(($filters['type'] ?? null) !== null, fn ($q) => $q->where('type', $filters['type']))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->where('reference', 'like', LikePattern::contains((string) $filters['search'])))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);

        $opening = $from === null ? 0 : $this->statements->openingBalance($account, $from);

        return ['page' => $page, 'opening_balance' => Pesos::fromCentavos($opening)];
    }

    /**
     * @param  Collection<int, SavingsTransaction>  $rows
     * @return list<array{date: string|null, reference: string, type: string, debit: string, credit: string, balance_after: string}>
     */
    public function passbookRows(Collection $rows): array
    {
        return $rows->map(fn (SavingsTransaction $row): array => [
            'date' => $row->date?->toDateString(),
            'reference' => $row->reference,
            'type' => $row->type,
            'debit' => Pesos::of((string) $row->getRawOriginal('debit')),
            'credit' => Pesos::of((string) $row->getRawOriginal('credit')),
            'balance_after' => Pesos::of((string) $row->getRawOriginal('balance_after')),
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> newest placement first */
    public function timeDeposits(): array
    {
        return $this->timeDepositQuery()
            ->orderByDesc('placement_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TimeDeposit $deposit) => $this->timeDeposit($deposit))
            ->values()
            ->all();
    }

    /**
     * One placement through TimeDepositPresenter, keeping only what a member
     * sees, plus the days to maturity on an open one.
     *
     * @return array<string, mixed>
     */
    public function timeDeposit(TimeDeposit $deposit): array
    {
        $figures = $this->timeDeposits->present($deposit);
        $open = in_array($deposit->status, Settlement::OPEN_STATUSES, true);
        $daysRemaining = $open
            ? max(0, (int) CarbonImmutable::parse(now()->toDateString())->diffInDays(CarbonImmutable::parse($figures['maturity_date']), false))
            : null;

        return [
            'id' => $figures['id'],
            'certificate_number' => $figures['certificate_number'],
            'product_name' => $figures['product']['name'] ?? null,
            'principal' => $figures['principal'],
            'annual_rate' => $figures['annual_rate'],
            'term_days' => $figures['term_days'],
            'placement_date' => $figures['placement_date'],
            'maturity_date' => $figures['maturity_date'],
            'days_remaining' => $daysRemaining,
            'maturing_soon' => $daysRemaining !== null && $daysRemaining <= self::MATURING_SOON_DAYS,
            'gross_interest' => $figures['gross_interest'],
            'withholding_tax' => $figures['withholding_tax'],
            'net_interest' => $figures['net_interest'],
            'maturity_amount' => $figures['maturity_amount'],
            'maturity_instruction' => $figures['maturity_instruction'],
            'status' => $figures['status'],
        ];
    }
}
