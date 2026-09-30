<?php

namespace App\Http\Requests\Loan;

use App\Models\Loan;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /loans/{loan}/submit: send a draft for review.
 *
 * `loans:update` submits any draft. A restructure application can also be
 * submitted with `loans:restructure` (owner decision 2026-09-30), so a role
 * that may raise a restructure can put it forward for review without being
 * able to edit loans. That holds for restructure applications only: an
 * ordinary draft still needs `loans:update`, and nothing else a
 * `loans:restructure` holder could not already do is opened up. Submitting
 * changes no terms; it moves the draft to `for_review` and seeds the approval
 * chain, which still has to be signed by the roles it names.
 */
class SubmitLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user->can('loans:update')) {
            return true;
        }

        $loan = $this->route('loan');

        return $loan instanceof Loan
            && $loan->isRestructure()
            && $user->can('loans:restructure');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
