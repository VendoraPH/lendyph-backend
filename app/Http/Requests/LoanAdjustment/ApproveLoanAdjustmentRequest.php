<?php

namespace App\Http\Requests\LoanAdjustment;

use Illuminate\Foundation\Http\FormRequest;

class ApproveLoanAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loan_adjustments:approve');
    }

    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
