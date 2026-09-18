<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reversing a posted entry.
 *
 * Carries NO lines, and that absence is the point. The mirror is built from the
 * entry's own STORED lines — a caller that could supply them could "reverse" an
 * entry into a different shape than the one it undoes, and the two would not
 * net to zero while both looked perfectly correct in isolation.
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
}
