<?php

namespace App\Services\CreditScoring\Api;

use App\Models\Borrower;
use App\Models\CreditApplicationProfile;
use App\Models\CreditPolicyFlag;
use App\Models\CreditScore;
use App\Models\CreditScoreMember;
use App\Models\Loan;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * What one caller may read of Credit Scoring (rounds m6 and fz): the members
 * `Borrower::visibleTo()` lets them see, never by the branch stored with a
 * score, and the loans `Loan::visibleTo()` lets them see. Pending and
 * rejected registrations are not members and are never shown.
 *
 * The engine reads a member across every branch, so a score stays whole;
 * what this hides are the identifiers of what the caller can't see.
 */
final class CreditScope
{
    public const MEMBER_COLUMNS = ['id', 'borrower_code', 'first_name', 'middle_name', 'last_name', 'suffix', 'branch_id', 'status'];

    public function __construct(private readonly BranchAccess $access) {}

    /**
     * A member the caller may see, or 404, like a missing one.
     */
    public function member(User $user, int $borrowerId): Borrower
    {
        return Borrower::query()
            ->visibleTo($user)
            ->members()
            ->select(self::MEMBER_COLUMNS)
            ->whereKey($borrowerId)
            ->firstOr(fn () => abort(404, 'Member not found.'));
    }

    /**
     * The ids of the members the caller may see, as a subquery.
     *
     * @return Builder<Borrower>
     */
    public function memberIds(User $user, ?int $branchId = null): Builder
    {
        return Borrower::query()
            ->visibleTo($user)
            ->members()
            ->when($branchId !== null, fn ($q) => $q->where('borrowers.branch_id', $branchId))
            ->select('borrowers.id');
    }

    /**
     * The ids of the loans the caller may see, as a subquery.
     *
     * @return Builder<Loan>
     */
    public function loanIds(User $user): Builder
    {
        return Loan::query()->visibleTo($user)->select('loans.id');
    }

    /**
     * The current scores of the members the caller may see, joined to the
     * member so they can be filtered and ordered by the member's own
     * (current) branch and name.
     *
     * @return Builder<CreditScoreMember>
     */
    public function currentScores(User $user, ?int $branchId = null): Builder
    {
        return CreditScoreMember::query()
            ->select('credit_score_members.*')
            ->join('borrowers', 'borrowers.id', '=', 'credit_score_members.borrower_id')
            ->whereIn('credit_score_members.borrower_id', $this->memberIds($user, $branchId));
    }

    /**
     * The members' own score history (not loan assessments), for the
     * members the caller may see.
     *
     * @return Builder<CreditScore>
     */
    public function history(User $user, ?int $branchId = null): Builder
    {
        return CreditScore::query()
            ->where('context', CreditScore::CONTEXT_NIGHTLY)
            ->whereIn('borrower_id', $this->memberIds($user, $branchId));
    }

    /**
     * Open policy flags of the members the caller may see: member-wide
     * flags, and an application's own flags only when its loan is visible.
     *
     * @return Builder<CreditPolicyFlag>
     */
    public function openFlags(User $user): Builder
    {
        return CreditPolicyFlag::query()
            ->open()
            ->whereIn('borrower_id', $this->memberIds($user))
            ->where(fn ($q) => $q->whereNull('loan_id')->orWhereIn('loan_id', $this->loanIds($user)));
    }

    /**
     * Whether the caller may read a score's financial figures: the member's
     * income, expenses and debt payments, and what is worked out from them
     * (capacity and the factor lines that print them). They come from the
     * member record and from an application's details, so the caller needs
     * `loans:view` and `borrowers:view`, the member's branch, and the loan
     * the application details came from (the engine can take them from any
     * of the member's loans, in any branch).
     */
    public function seesFinancials(User $user, Borrower $member, ?CreditScore $score): bool
    {
        if (! $user->can('loans:view') || ! $this->seesMemberDetails($user, $member)) {
            return false;
        }

        $profileId = $score?->data_used['application_profile_id'] ?? null;

        if ($profileId === null) {
            return true;
        }

        $loanId = CreditApplicationProfile::query()->whereKey((int) $profileId)->value('loan_id');

        return $loanId !== null && Loan::query()->visibleTo($user)->whereKey($loanId)->exists();
    }

    /**
     * Whether the caller may read the member's personal details (round fz):
     * `borrowers:view` and the member's branch.
     */
    public function seesMemberDetails(User $user, Borrower $member): bool
    {
        return $user->can('borrowers:view')
            && $this->access->canSee($user, $member->branch_id === null ? null : (int) $member->branch_id);
    }

    public function seesEveryBranch(User $user): bool
    {
        return $this->access->for($user) === null;
    }

    /**
     * A score's `data_used` as the caller may read it: the member-wide
     * figures stay, `loan_ids` and `branch_ids` keep only what the caller
     * can see, and `other_branch_loans` counts the loans left out.
     *
     * @param  array<string, mixed>|null  $dataUsed
     * @return array<string, mixed>|null
     */
    public function dataUsed(User $user, ?array $dataUsed): ?array
    {
        if ($dataUsed === null) {
            return null;
        }

        $loanIds = array_map('intval', $dataUsed['loan_ids'] ?? []);
        $visibleLoans = $loanIds === []
            ? []
            : Loan::query()->visibleTo($user)->whereIn('loans.id', $loanIds)->pluck('loans.id')->map(fn ($id) => (int) $id)->all();
        $keptLoans = array_values(array_filter($loanIds, fn (int $id) => in_array($id, $visibleLoans, true)));

        $branchIds = array_map('intval', $dataUsed['branch_ids'] ?? []);
        $visibleBranches = $this->access->for($user);
        $keptBranches = $visibleBranches === null
            ? $branchIds
            : array_values(array_filter($branchIds, fn (int $id) => in_array($id, $visibleBranches, true)));

        return array_merge($dataUsed, [
            'loan_ids' => $keptLoans,
            'branch_ids' => $keptBranches,
            'other_branch_loans' => count($loanIds) - count($keptLoans),
        ]);
    }
}
