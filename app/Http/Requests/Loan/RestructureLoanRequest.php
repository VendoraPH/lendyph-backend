<?php

namespace App\Http\Requests\Loan;

use App\Enums\LoanFrequency;
use App\Http\Requests\Concerns\ExcludesRejectedBorrowers;
use App\Rules\ExistingCoMakerOrBorrower;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for POST /loans/{loan}/restructure.
 *
 * Mirrors StoreLoanRequest — a restructure creates a normal loan application,
 * it just draws its principal from the source loan's outstanding balance — plus
 * `remarks`, which LoanService::restructure() requires when the new principal
 * falls short of that balance (a shortfall writes debt off, so it carries a
 * reason).
 *
 * `interest_method` is deliberately absent: it is always snapshotted from the
 * loan product, never accepted from the request, exactly as POST /loans does.
 */
class RestructureLoanRequest extends FormRequest
{
    use ExcludesRejectedBorrowers;

    public function authorize(): bool
    {
        return $this->user()->can('loans:restructure');
    }

    public function rules(): array
    {
        return [
            'borrower_id' => ['required', $this->nonRejectedBorrowerRule()],
            'co_maker_ids' => ['nullable', 'array'],
            // Must exist as a co-maker OR a non-rejected borrower — both are
            // valid inputs to createLoan(), and the co-maker picker sends
            // borrower ids. StoreLoanRequest now carries the identical rule;
            // it used to be a bare `integer`, which let arbitrary unvalidated
            // ids reach that lookup.
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
            'remarks' => ['nullable', 'string', 'max:1000'],
            'deductions' => ['nullable', 'array'],
            'deductions.*.name' => ['required_with:deductions', 'string', 'max:255'],
            'deductions.*.amount' => ['required_with:deductions', 'numeric', 'min:0'],
            'deductions.*.type' => ['required_with:deductions', 'in:fixed,percentage'],
        ];
    }
}
