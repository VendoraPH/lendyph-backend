<?php

namespace App\Http\Requests\Loan;

use App\Http\Requests\Concerns\RequiresActiveAccountOfficer;
use Illuminate\Foundation\Http\FormRequest;

class AssignAccountOfficerRequest extends FormRequest
{
    use RequiresActiveAccountOfficer;

    public function authorize(): bool
    {
        return $this->user()->can('loans:update');
    }

    public function rules(): array
    {
        return [
            // Only an active user can carry a loan — see RequiresActiveAccountOfficer.
            'account_officer_id' => ['required', 'integer', $this->activeAccountOfficerRule()],
        ];
    }

    public function messages(): array
    {
        return [
            'account_officer_id.exists' => $this->activeAccountOfficerMessage(),
        ];
    }
}
