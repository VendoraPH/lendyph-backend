<?php

namespace App\Rules;

use App\Models\Borrower;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A loan takes its member's branch, and `loans.branch_id` is NOT NULL, so a
 * loan for a member with no branch failed as a raw integrity error (Phase 6
 * FX-1, D7). This refuses it in words instead. The id's own existence and
 * branch-scope checks stay with the field's `exists` rule.
 */
final class MemberHasBranch implements ValidationRule
{
    public const MESSAGE = 'This member has no branch yet. Set the member\'s branch before creating a loan.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // The same whole numbers the `integer` and `exists` rules accept
        // (" 5" and 5.0 included), so none of them slips past to the insert.
        $id = filter_var($value, FILTER_VALIDATE_INT);

        if ($id === false) {
            return;
        }

        $member = Borrower::query()->whereKey($id)->first(['id', 'branch_id']);

        // A missing member is the `exists` rule's to report.
        if ($member !== null && $member->branch_id === null) {
            $fail(self::MESSAGE);
        }
    }
}
