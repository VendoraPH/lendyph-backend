<?php

namespace App\Http\Requests\Loan;

use App\Enums\LoanFrequency;
use App\Http\Requests\Concerns\ExcludesRejectedBorrowers;
use App\Rules\ExistingCoMakerOrBorrower;
use Illuminate\Foundation\Http\FormRequest;

class StoreLoanRequest extends FormRequest
{
    use ExcludesRejectedBorrowers;

    public function authorize(): bool
    {
        return $this->user()->can('loans:create');
    }

    public function rules(): array
    {
        return [
            'borrower_id' => ['required', $this->nonRejectedBorrowerRule()],
            'co_maker_ids' => ['nullable', 'array'],
            // Same rule as the principal borrower above and as
            // RestructureLoanRequest: a co-maker is jointly liable, so a
            // rejected registration must not become one. A bare `integer` let
            // any id at all reach LoanService::createLoan(), which resolves it
            // as a borrower and binds that person to the loan.
            'co_maker_ids.*' => ['integer', new ExistingCoMakerOrBorrower],
            'loan_product_id' => ['required', 'exists:loan_products,id'],
            'principal_amount' => ['required', 'numeric', 'min:1'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'term' => ['nullable', 'integer', 'min:1'],
            'frequency' => ['nullable', LoanFrequency::rule()],
            'start_date' => ['required', 'date'],
            'account_officer_id' => ['nullable', 'exists:users,id'],
            'scb_amount' => ['nullable', 'numeric', 'min:0'],
            'policy_exception' => ['nullable', 'boolean'],
            'policy_exception_details' => ['nullable', 'string', 'max:2000'],
            'deductions' => ['nullable', 'array'],
            'deductions.*.name' => ['required_with:deductions', 'string', 'max:255'],
            'deductions.*.amount' => ['required_with:deductions', 'numeric', 'min:0'],
            'deductions.*.type' => ['required_with:deductions', 'in:fixed,percentage'],
        ];
    }
}
