<?php

namespace App\Http\Requests\Accounting;

use App\Models\AccountingJournal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Posting a draft. Carries no body: the entry posts exactly as stored.
 *
 * A draft with no branch, a line on the allowance and a date after today in
 * Manila is refused on `date`, by the same rule and in the same words as
 * create and edit ({@see RefusesFutureNoBranchAllowanceDates}). Create and
 * edit already refuse such a draft, so this catches one saved before the rule
 * existed. Anything that isn't a draft is left to JournalPoster, which refuses
 * it in its own words.
 */
class PostJournalRequest extends FormRequest
{
    use RefusesFutureNoBranchAllowanceDates;

    public function authorize(): bool
    {
        return $this->user()->can('journals:post');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $journal = $this->route('journal');

                if (! $journal instanceof AccountingJournal || $journal->status !== 'draft' || $journal->date === null) {
                    return;
                }

                $refusal = $this->futureNoBranchAllowanceRefusal(
                    $journal->branch_id,
                    $journal->date->toDateString(),
                    $journal->lines()->pluck('accounting_account_id')->all(),
                );

                if ($refusal !== null) {
                    $validator->errors()->add('date', $refusal);
                }
            },
        ];
    }
}
