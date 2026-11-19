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
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return;
        }

        $member = Borrower::query()->whereKey((int) $value)->first(['id', 'branch_id']);

        // A missing member is the `exists` rule's to report.
        if ($member !== null && $member->branch_id === null) {
            $fail(self::MESSAGE);
        }
    }
}
