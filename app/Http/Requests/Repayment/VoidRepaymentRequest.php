<?php

namespace App\Http\Requests\Repayment;

use Illuminate\Foundation\Http\FormRequest;

class VoidRepaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('repayments.void');
    }

    public function rules(): array
    {
        return [
            'void_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
