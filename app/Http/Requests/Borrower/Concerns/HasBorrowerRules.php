<?php

namespace App\Http\Requests\Borrower\Concerns;

use Illuminate\Validation\Rule;

trait HasBorrowerRules
{
    /**
     * Field rules shared between StoreBorrowerRequest and UpdateBorrowerRequest.
     * Identity fields (first/last/branch) are intentionally left out so each
     * request can apply its own `required` vs `sometimes` modifier.
     *
     * @param  int|null  $ignoreId  Current borrower id when updating (for unique rules).
     * @return array<string, array<int, mixed>>
     */
    protected function sharedBorrowerRules(?int $ignoreId = null): array
    {
        return [
            'middle_name' => ['nullable', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'birthdate' => ['nullable', 'date', 'after:1900-01-01', 'before:today'],
            'civil_status' => ['nullable', 'string', 'in:single,married,widowed,separated,divorced'],
            'gender' => ['nullable', 'string', 'in:male,female'],

            // Legacy single-line address is kept for backward compatibility;
            // structured fields below are preferred going forward.
            'address' => ['nullable', 'string', 'max:1000'],
            'street_address' => ['nullable', 'string', 'max:255'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],

            'contact_number' => [
                'nullable',
                'string',
                'max:20',
                // Accepts PH `09xxxxxxxxx`, landline formats, or international `+...`
                'regex:/^(\+?\d{7,15}|0\d{9,10})$/',
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('borrowers', 'email')->ignore($ignoreId),
            ],
            'employer_or_business' => ['nullable', 'string', 'max:255'],
            'monthly_income' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'pledge_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],

            'spouse_first_name' => ['nullable', 'string', 'max:255'],
            'spouse_middle_name' => ['nullable', 'string', 'max:255'],
            'spouse_last_name' => ['nullable', 'string', 'max:255'],
            'spouse_contact_number' => ['nullable', 'string', 'max:20'],
            'spouse_occupation' => ['nullable', 'string', 'max:255'],

            // Opt-out of the duplicate-borrower check. When omitted or false,
            // the NoDuplicateBorrower rule runs on the first_name field.
            'force' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function borrowerMessages(): array
    {
        return [
            'contact_number.regex' => 'The contact number must be a valid phone format (e.g., 09171234567 or +639171234567).',
            'email.unique' => 'A borrower with this email already exists.',
            'pledge_amount.max' => 'The pledge amount may not exceed 9,999,999.99.',
            'birthdate.after' => 'The birthdate must be after January 1, 1900.',
        ];
    }
}
