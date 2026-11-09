<?php

namespace App\Http\Requests\CreditScoring;

use App\Models\CreditDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `CreateCreditDecisionData`, plus an optional `loan_id`. The member and
 * score are checked against the caller's scope by the controller: a member
 * they can't see answers 404, a score of another member 422.
 */
class StoreCreditDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('credit_scoring:override');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'borrower_id' => ['required', 'integer', 'min:1'],
            'credit_score_id' => ['required', 'integer', 'min:1'],
            'loan_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'decision' => ['required', 'string', Rule::in(CreditDecision::DECISIONS)],
            'reason' => ['required', 'string', 'max:255'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
