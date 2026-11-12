<?php

namespace App\Services\Savings\Reports;

use App\Models\Borrower;
use App\Models\SavingsAccount;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\Savings\Api\AccountScope;
use App\Services\Savings\SavingsMath;
use Carbon\CarbonImmutable;

/**
 * A member's deposit statement (gap round S5, the Savings task's TASK 36):
 * each of the member's savings accounts in the caller's branches, as its own
 * statement for the period (StatementBuilder), and the member's time
 * deposits outstanding at some point in it, with totals across them.
 *
 * The member must be in the caller's branches (another branch's member
 * answers 404, the same as a missing one). Only the member's id, name, code
 * and branch are shown: no contact details or address. The period defaults
 * to the member's first account opening, or today, through today.
 */
class MemberStatement
{
    public function __construct(
        private readonly StatementBuilder $statements,
        private readonly SavingsReports $reports,
        private readonly AccountScope $scope,
        private readonly BranchAccess $branches,
    ) {}

    public function member(User $user, int $borrowerId): Borrower
    {
        return $this->branches->constrain(Borrower::query(), $user, 'branch_id')
            ->with('branch')
            ->whereKey($borrowerId)
            ->firstOr(fn () => abort(404, 'Member not found.'));
    }

    /** @return array<string, mixed> */
    public function build(User $user, Borrower $member, ?string $dateFrom, ?string $dateTo): array
    {
        $accounts = $this->scope->accounts($user)
            ->with(['borrower', 'branch', 'product'])
            ->where('borrower_id', $member->id)
            ->orderBy('account_number')
            ->orderBy('id')
            ->get();

        $today = now()->toDateString();
        $from = $dateFrom ?? ($accounts->min(fn (SavingsAccount $account) => $account->opened_on->toDateString()) ?? $today);
        $to = $dateTo ?? CarbonImmutable::parse($today)->max(CarbonImmutable::parse($from))->toDateString();

        $statements = $accounts->map(fn (SavingsAccount $account) => $this->statements->build($account, $from, $to))->values()->all();

        $deposits = TimeDeposit::query()
            ->with(['borrower', 'branch', 'product'])
            ->where('borrower_id', $member->id)
            ->when($this->branches->for($user), fn ($q, array $ids) => $q->whereIn('branch_id', $ids))
            ->when($this->branches->for($user) === [], fn ($q) => $q->whereRaw('1 = 0'))
            ->where('placement_date', '<=', $to)
            ->where(fn ($q) => $q->whereNull('settled_on')->orWhere('settled_on', '>=', $from))
            ->orderBy('placement_date')
            ->orderBy('id')
            ->get();

        $isOutstanding = fn (TimeDeposit $deposit): bool => $deposit->settled_on === null || $deposit->settled_on->toDateString() > $to;
        $outstanding = $deposits->filter($isOutstanding);
        $sum = fn (string $field) => array_sum(array_map(fn (array $statement) => SavingsMath::signedCentavos(data_get($statement, $field)), $statements));
        $closing = $sum('closing_balance');
        $principal = $outstanding->sum(fn (TimeDeposit $deposit) => SavingsMath::centavos($deposit->principal));

        return [
            'member' => [
                'id' => $member->id,
                'name' => $member->full_name,
                'code' => $member->borrower_code,
                'branch_id' => $member->branch_id,
                'branch_name' => $member->branch?->name,
            ],
            'date_from' => $from,
            'date_to' => $to,
            'accounts' => $statements,
            'time_deposits' => $deposits->map(fn (TimeDeposit $deposit) => [
                ...$this->reports->maturityRow($deposit),
                'settled_on' => $deposit->settled_on?->toDateString(),
                'outstanding' => $isOutstanding($deposit),
            ])->values()->all(),
            'totals' => [
                'account_count' => count($statements),
                'opening_balance' => SavingsMath::pesos($sum('opening_balance')),
                'credit' => SavingsMath::pesos($sum('totals.credit')),
                'debit' => SavingsMath::pesos($sum('totals.debit')),
                'closing_balance' => SavingsMath::pesos($closing),
                'time_deposit_count' => $outstanding->count(),
                'time_deposits' => SavingsMath::pesos($principal),
                'total_deposits' => SavingsMath::pesos($closing + $principal),
            ],
        ];
    }
}
