<?php

namespace App\Http\Requests\Loan;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loans:create');
    }

    public function rules(): array
    {
        return [
            'borrower_id' => ['required', 'exists:borrowers,id'],
            'co_maker_ids' => ['nullable', 'array'],
            'co_maker_ids.*' => ['integer'],
            'loan_product_id' => ['required', 'exists:loan_products,id'],
            'principal_amount' => ['required', 'numeric', 'min:1'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'term' => ['nullable', 'integer', 'min:1'],
            'frequency' => ['nullable', 'in:daily,weekly,semi_monthly,monthly'],
            'start_date' => ['required', 'date'],
            'account_officer_id' => ['nullable', 'exists:users,id'],
            'deductions' => ['nullable', 'array'],
            'deductions.*.name' => ['required_with:deductions', 'string', 'max:255'],
            'deductions.*.amount' => ['required_with:deductions', 'numeric', 'min:0'],
            'deductions.*.type' => ['required_with:deductions', 'in:fixed,percentage'],
        ];
    }
}
