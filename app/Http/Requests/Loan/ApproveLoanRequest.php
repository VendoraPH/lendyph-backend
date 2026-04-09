<?php

namespace App\Http\Requests\Loan;

use Illuminate\Foundation\Http\FormRequest;

class ApproveLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loans:approve');
    }

    public function rules(): array
    {
        return [
            'approval_remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
