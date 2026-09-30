<?php

namespace App\Http\Requests\Loan;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignAccountOfficerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('loans:update');
    }

    public function rules(): array
    {
        return [
            // Only an active user can carry a loan. A deactivated account
            // cannot sign in, so it would leave the loan with nobody following it up.
            'account_officer_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('status', 'active'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'account_officer_id.exists' => 'Choose an active user as the account officer.',
        ];
    }
}
