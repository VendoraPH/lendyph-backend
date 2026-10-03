<?php

namespace App\Http\Requests\Loan;

use App\Enums\LoanFrequency;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The loan form's figures, asked for while it is being filled in: every field
 * is optional, and the preview computes whatever its sections can from what
 * was sent (LoanService::formPreview()).
 *
 * Open to whoever can create or edit a loan, the two uses of the form. The
 * product's own rules (amount, rate and term ranges) are not checked here: the
 * form states them beside its fields, and saving the loan enforces them.
 */
class PreviewLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canAny(['loans:create', 'loans:update']);
    }

    public function rules(): array
    {
        return [
            'loan_product_id' => ['nullable', 'integer', 'exists:loan_products,id'],
            'principal_amount' => ['nullable', 'numeric', 'min:0'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'term' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'frequency' => ['nullable', LoanFrequency::rule()],
            'start_date' => ['nullable', 'date'],
            'scb_amount' => ['nullable', 'numeric', 'min:0'],
            'collaterals' => ['nullable', 'array'],
            'collaterals.*.collateral_id' => ['nullable', 'integer'],
            'collaterals.*.snapshot_value' => ['required', 'numeric', 'min:0'],
        ];
    }
}
