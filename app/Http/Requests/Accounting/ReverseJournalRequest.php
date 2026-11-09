<?php

namespace App\Http\Requests\Accounting;

use App\Models\AccountingJournal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Reversing a posted entry.
 *
 * Carries NO lines, and that absence is the point. The mirror is built from the
 * entry's own STORED lines — a caller that could supply them could "reverse" an
 * entry into a different shape than the one it undoes, and the two would not
 * net to zero while both looked perfectly correct in isolation.
 *
 * The reversal is never dated before the entry it reverses, given or by
 * default (today): it would undo the entry before the entry happened, and
 * every balance between the two dates would read the pair as a lone credit.
 */
class ReverseJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('journals:reverse');
    }

    public function rules(): array
    {
        return [
            // The date the reversal LANDS on, which is routinely not the
            // original's: reversing a September entry in October belongs in
            // October, or a period that has already been reported on silently
            // changes. Optional, defaulting to today.
            'date' => ['sometimes', 'nullable', 'date'],
            // Folded into the reversal's description, because there is no
            // column for it and it is the most useful half of the record.
            'reason' => ['sometimes', 'nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.date' => 'The reversal date isn\'t a valid date.',
            'reason.max' => 'The reason can be at most 300 characters.',
        ];
    }

    /**
     * Refuses a reversal dated before the entry it reverses, in words, once
     * the date itself is valid.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $journal = $this->route('journal');

                if ($validator->errors()->has('date') || ! $journal instanceof AccountingJournal || $journal->date === null) {
                    return;
                }

                $reversalDate = Carbon::parse($this->input('date') ?: now()->toDateString())->startOfDay();
                $entryDate = $journal->date->copy()->startOfDay();

                if ($reversalDate->lt($entryDate)) {
                    $validator->errors()->add('date', sprintf(
                        'The reversal can\'t be dated before the entry it reverses (%1$s). Choose %1$s or later.',
                        $entryDate->format('M j, Y'),
                    ));
                }
            },
        ];
    }
}
