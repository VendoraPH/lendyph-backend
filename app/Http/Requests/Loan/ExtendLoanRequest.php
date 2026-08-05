<?php

namespace App\Http\Requests\Loan;

use Illuminate\Foundation\Http\FormRequest;

class ExtendLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loans:extend');
    }

    /**
     * `interest_option` is required rather than defaulted on purpose. The
     * extension now collects the interest itself, so a silent default of 'pay'
     * would double-charge the partial-payment flow, which posts the cashier's
     * own repayment immediately before extending. Every caller states intent.
     */
    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:1000'],
            'interest_option' => ['required', 'in:pay,defer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'interest_option.required' => 'Choose whether to pay or defer the outstanding interest.',
        ];
    }
}
