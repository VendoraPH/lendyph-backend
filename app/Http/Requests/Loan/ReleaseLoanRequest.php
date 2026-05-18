<?php

namespace App\Http\Requests\Loan;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReleaseLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loans:release');
    }

    public function rules(): array
    {
        return [
            'insurance_premium_percentage' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'insurance_premium_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'insurance_payment_type' => ['nullable', Rule::in(['full', 'partial'])],
            'insurance_partial_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'lte:insurance_premium_amount'],
            'insurance_remaining_balance' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $pct = $this->input('insurance_premium_percentage');
            if ($pct === null || (float) $pct === 0.0) {
                return;
            }

            $paymentType = $this->input('insurance_payment_type', 'full');
            $partial = $this->input('insurance_partial_amount');

            if ($paymentType === 'partial' && ($partial === null || $partial === '')) {
                $v->errors()->add(
                    'insurance_partial_amount',
                    'The insurance partial amount is required when payment type is partial.',
                );
            }

            if ($paymentType === 'full' && $partial !== null && $partial !== '') {
                $v->errors()->add(
                    'insurance_partial_amount',
                    'The insurance partial amount must be null when payment type is full.',
                );
            }
        });
    }

    /**
     * Subset of validated payload destined for LoanService::release.
     *
     * @return array<string, mixed>
     */
    public function insurancePayload(): array
    {
        return $this->only([
            'insurance_premium_percentage',
            'insurance_premium_amount',
            'insurance_payment_type',
            'insurance_partial_amount',
            'insurance_remaining_balance',
        ]);
    }
}
