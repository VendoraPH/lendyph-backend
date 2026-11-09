<?php

namespace App\Http\Requests\Loan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /loans/{loan}/send-back-approved: whoever may release a loan may send
 * an approved restructure back for approval instead, and always says why.
 */
class SendBackApprovedLoanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loans:release');
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
