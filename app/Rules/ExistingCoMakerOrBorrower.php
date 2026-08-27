<?php

namespace App\Rules;

use App\Models\Borrower;
use App\Models\CoMaker;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts a `co_maker_ids` entry that is either a co-maker id or a borrower id.
 *
 * Both are legitimate: `LoanService::createLoan()` resolves the id as a CoMaker
 * first and otherwise treats it as a Borrower, creating a co-maker record from
 * that person. The co-maker pickers on the loan and restructure forms are
 * populated from borrowers and send borrower ids, so a co-makers-only rule would
 * reject every id a working form produces.
 *
 * What this does close: without it the field was a bare `integer`, so arbitrary
 * unvalidated ids reached that lookup — probing rows and binding whoever came
 * back to a live loan.
 *
 * The borrower branch excludes `rejected` registrations, matching
 * ExcludesRejectedBorrowers on the principal `borrower_id`. A co-maker is
 * jointly liable for the loan, so admitting somebody the cooperative turned
 * away as a co-maker is the same defect as admitting them as the borrower —
 * gating one and not the other just moves the hole one field to the right.
 * `pending` stays acceptable for the same reason it does there: pending
 * borrowers demonstrably hold live loans in production.
 */
class ExistingCoMakerOrBorrower implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            $fail('The :attribute must be an existing co-maker or borrower id.');

            return;
        }

        if (CoMaker::whereKey($value)->exists()) {
            return;
        }

        if (Borrower::whereKey($value)->whereNot('status', 'rejected')->exists()) {
            return;
        }

        $fail('The :attribute must be an existing co-maker or borrower id.');
    }
}
