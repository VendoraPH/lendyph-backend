<?php

namespace App\Http\Requests\Loan;

use App\Http\Requests\Concerns\ExcludesRejectedBorrowers;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLoanRequest extends FormRequest
{
    use ExcludesRejectedBorrowers;

    public function authorize(): bool
    {
        return $this->user()->can('loans:update');
    }

    public function rules(): array
    {
        return [
            'co_maker_ids' => ['nullable', 'array'],
            // MEMBER ids only — all the loan form's co-maker picker sends —
            // never a co-maker record id: the two are separate sequences, so a
            // number accepted as either can name two different people. See
            // LoanService::coMakerIdsForMembers(). The same rule as the
            // principal borrower on StoreLoanRequest, because a co-maker is
            // jointly liable: a rejected registration must not become one.
            'co_maker_ids.*' => ['integer', $this->nonRejectedBorrowerRule()],
            'principal_amount' => ['sometimes', 'numeric', 'min:1'],
            'purpose' => ['nullable', 'string', 'max:500'],
            // Only consumed when the principal of a RESTRUCTURE is being changed:
            // that re-runs the shortfall rules, which require a reason. There is
            // no `remarks` column on loans, so it never reaches the row itself —
            // LoanService maps it onto `restructure_remarks`.
            'remarks' => ['nullable', 'string', 'max:1000'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'start_date' => ['sometimes', 'date'],
            'scb_amount' => ['nullable', 'numeric', 'min:0'],
            'policy_exception' => ['nullable', 'boolean'],
            'policy_exception_details' => ['nullable', 'string', 'max:2000'],
            'deductions' => ['nullable', 'array'],
            'deductions.*.name' => ['required_with:deductions', 'string', 'max:255'],
            'deductions.*.amount' => ['required_with:deductions', 'numeric', 'min:0'],
            'deductions.*.type' => ['required_with:deductions', 'in:fixed,percentage'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'co_maker_ids.*.exists' => 'Each co-maker must be an existing member who was not rejected.',
        ];
    }
}
