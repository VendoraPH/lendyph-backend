<?php

namespace App\Services\Savings\Api;

use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which savings records a caller may see (design §0, least privilege): an
 * account in one of their branches (BranchAccess), and everything hanging off
 * it (its transactions, holds and approvals). A record outside their branches
 * answers the same 404 as a missing one, on every `{id}` route. Built like
 * CreditScoring\Api\CreditScope.
 */
class AccountScope
{
    public function __construct(private readonly BranchAccess $branches) {}

    /** @return Builder<SavingsAccount> */
    public function accounts(User $user): Builder
    {
        return $this->branches->constrain(SavingsAccount::query(), $user, 'savings_accounts.branch_id');
    }

    /** One visible account (unlocked), or 404. */
    public function account(User $user, int $id): SavingsAccount
    {
        return $this->accounts($user)->whereKey($id)->firstOr(fn () => abort(404, 'Savings account not found.'));
    }

    /** @return Builder<SavingsTransaction> */
    public function transactions(User $user): Builder
    {
        return SavingsTransaction::query()->whereIn('savings_transactions.account_id', $this->accountIds($user));
    }

    public function transaction(User $user, int $id): SavingsTransaction
    {
        return $this->transactions($user)->whereKey($id)->firstOr(fn () => abort(404, 'Savings transaction not found.'));
    }

    public function hold(User $user, int $id): SavingsHold
    {
        return SavingsHold::query()->whereIn('account_id', $this->accountIds($user))->whereKey($id)
            ->firstOr(fn () => abort(404, 'Hold not found.'));
    }

    /**
     * Approvals on a visible account, or on a visible time deposit (slice S3's
     * kinds carry no account).
     *
     * @return Builder<SavingsApproval>
     */
    public function approvals(User $user): Builder
    {
        $branchIds = $this->branches->for($user);

        if ($branchIds === null) {
            return SavingsApproval::query();
        }

        return SavingsApproval::query()->where(fn (Builder $q) => $q
            ->whereIn('savings_approvals.account_id', $this->accountIds($user))
            ->orWhereIn('savings_approvals.time_deposit_id', TimeDeposit::query()->whereIn('branch_id', $branchIds)->select('id')));
    }

    public function approval(User $user, int $id): SavingsApproval
    {
        return $this->approvals($user)->whereKey($id)->firstOr(fn () => abort(404, 'Approval request not found.'));
    }

    /** @return Builder<SavingsAccount> the visible account ids, as a subquery */
    public function accountIds(User $user): Builder
    {
        return $this->accounts($user)->select('savings_accounts.id');
    }
}
