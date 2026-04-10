<?php

namespace App\Http\Requests\Loan;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loans:update');
    }

    public function rules(): array
    {
        return [
            'co_maker_ids' => ['nullable', 'array'],
            'co_maker_ids.*' => ['exists:co_makers,id'],
            'principal_amount' => ['sometimes', 'numeric', 'min:1'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'start_date' => ['sometimes', 'date'],
            'deductions' => ['nullable', 'array'],
            'deductions.*.name' => ['required_with:deductions', 'string', 'max:255'],
            'deductions.*.amount' => ['required_with:deductions', 'numeric', 'min:0'],
            'deductions.*.type' => ['required_with:deductions', 'in:fixed,percentage'],
        ];
    }
}
