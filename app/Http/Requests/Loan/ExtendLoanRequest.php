<?php

namespace App\Http\Requests\Loan;

use Illuminate\Foundation\Http\FormRequest;

class ExtendLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loans:extend');
    }

    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
