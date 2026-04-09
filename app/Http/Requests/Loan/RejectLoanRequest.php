<?php

namespace App\Http\Requests\Loan;

use Illuminate\Foundation\Http\FormRequest;

class RejectLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loans:reject');
    }

    public function rules(): array
    {
        return [
            'approval_remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
