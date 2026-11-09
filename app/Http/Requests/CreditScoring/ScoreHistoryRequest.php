<?php

namespace App\Http\Requests\CreditScoring;

use App\Services\BranchAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the score history (`ScoreHistoryFilters`): `from` and `to`
 * are inclusive Manila dates. A `borrower_id` outside the caller's branches
 * fails like a missing one; a `branch_id` outside them answers 403 in the
 * controller.
 */
class ScoreHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('credit_scoring:view');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'borrower_id' => ['sometimes', 'integer', app(BranchAccess::class)->constrainExists(Rule::exists('borrowers', 'id'), $this->user())],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', Rule::when($this->filled('from'), 'after_or_equal:from')],
        ];
    }
}
