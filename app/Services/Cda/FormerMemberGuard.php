<?php

namespace App\Services\Cda;

use App\Models\Borrower;
use App\Models\Loan;
use App\Rules\NotAFormerMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * No new credit to a former member (C1 review, H1, M2 and L2): a loan isn't
 * released while its member, or any of its co-makers (by their member
 * record), has withdrawn or died.
 *
 * The release request checks it before the release starts, and
 * LoanService::release() checks it again under the loan lock, reading the
 * member rows with a shared lock, so a membership ended at the same moment
 * (MembershipStatus locks the member row) is seen either way.
 */
final class FormerMemberGuard
{
    /**
     * What stops the loan's release, keyed by field: `borrower_id` for its
     * member, `co_maker_ids` for its co-makers.
     *
     * @return array<string, list<string>>
     */
    public function releaseRefusals(Loan $loan, bool $lock = false): array
    {
        $coMakerMemberIds = DB::table('co_maker_loan')
            ->join('co_makers', 'co_makers.id', '=', 'co_maker_loan.co_maker_id')
            ->where('co_maker_loan.loan_id', $loan->getKey())
            ->whereNotNull('co_makers.member_borrower_id')
            ->pluck('co_makers.member_borrower_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $ids = array_values(array_unique([(int) $loan->borrower_id, ...$coMakerMemberIds]));
        sort($ids);

        $members = Borrower::query()->whereKey($ids)->orderBy('id')
            ->when($lock, fn ($query) => $query->sharedLock())
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'borrower_code', 'status', 'membership_ended_on'])
            ->keyBy('id');

        $refusals = [];
        $member = $members->get((int) $loan->borrower_id);

        if ($member !== null && $member->isFormerMember()) {
            $refusals['borrower_id'][] = NotAFormerMember::messageFor($member);
        }

        foreach ($coMakerMemberIds as $id) {
            $coMaker = $members->get($id);

            if ($coMaker !== null && $coMaker->isFormerMember()) {
                $refusals['co_maker_ids'][] = NotAFormerMember::messageFor($coMaker, NotAFormerMember::AS_CO_MAKER);
            }
        }

        return $refusals;
    }

    /**
     * No new credit on a loan whose member has withdrawn or died, re-checked
     * under the caller's loan lock with the member row read under a shared
     * lock: an extension on "defer" (C1 re-review, L2).
     *
     * @throws ValidationException 422 in words, on `$field`
     */
    public function assertMemberCurrent(Loan $loan, string $field, string $advice = ''): void
    {
        $member = Borrower::query()->whereKey($loan->borrower_id)->sharedLock()
            ->first(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'borrower_code', 'status', 'membership_ended_on']);

        if ($member !== null && $member->isFormerMember()) {
            throw ValidationException::withMessages([$field => trim(NotAFormerMember::messageFor($member).' '.$advice)]);
        }
    }

    /** @throws ValidationException 422 in words when the loan can't be released */
    public function assertReleasable(Loan $loan): void
    {
        $refusals = $this->releaseRefusals($loan, lock: true);

        if ($refusals !== []) {
            throw ValidationException::withMessages($refusals);
        }
    }
}
