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
            // Applicants must be at least 18 (PH age of majority for lending).
            // `before_or_equal:-18 years` also rejects future dates implicitly.
            'birthdate' => ['nullable', 'date', 'after:1900-01-01', 'before_or_equal:-18 years'],
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
            'date_hired' => ['nullable', 'date', 'after:1900-01-01', 'before_or_equal:today'],
            'monthly_income' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'pledge_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],

            'spouse_first_name' => ['nullable', 'string', 'max:255'],
            'spouse_middle_name' => ['nullable', 'string', 'max:255'],
            'spouse_last_name' => ['nullable', 'string', 'max:255'],
            'spouse_contact_number' => ['nullable', 'string', 'max:20'],
            'spouse_occupation' => ['nullable', 'string', 'max:255'],

            // `pending` is reserved for the public registration flow — every
            // other status is operator-only. StoreBorrowerRequest narrows this
            // list when the request is anonymous.
            'status' => ['nullable', 'string', 'in:active,inactive,blacklisted,pending'],

            // Opt-out of the duplicate-borrower check. When omitted or false,
            // the NoDuplicateBorrower rule runs on the first_name field.
            'force' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The fields every member form requires, on top of sharedBorrowerRules():
     * the staff forms and the public registration ask for the same ones
     * (audit finding 15). A create must send them. An update may leave one out
     * but can't clear it.
     *
     * The street address lives in either `address` or `street_address`, and the
     * forms send one or both, so one of the two is what's required.
     *
     * The CSV importer doesn't come through here: it imports a legacy member
     * with a blank field on purpose (CustomerRowNormalizer).
     *
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, array<int, mixed>>
     */
    protected function requireMemberFields(array $rules, bool $creating): array
    {
        $presence = $creating ? [] : ['sometimes'];

        foreach (['birthdate', 'civil_status', 'gender', 'contact_number', 'city', 'province'] as $field) {
            $rules[$field] = [
                ...$presence,
                'required',
                ...array_values(array_diff($rules[$field], ['nullable'])),
            ];
        }

        $rules['address'] = [...$presence, 'required_without:street_address', ...$rules['address']];
        $rules['street_address'] = [...$presence, 'required_without:address', ...$rules['street_address']];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function borrowerMessages(): array
    {
        return [
            'address.required_without' => 'The street address is required.',
            'street_address.required_without' => 'The street address is required.',
            'contact_number.regex' => 'The contact number must be a valid phone format (e.g., 09171234567 or +639171234567).',
            // Same reasoning as NoDuplicateBorrower: public registration is
            // anonymous, so confirming that an address is already on file lets
            // anyone enumerate who borrows here. Operators still get the direct
            // message, since they need it to resolve the collision.
            'email.unique' => $this->user()
                ? 'A member with this email already exists.'
                : 'This email cannot be used. Please contact your branch to continue.',
            'pledge_amount.max' => 'The pledge amount may not exceed 9,999,999.99.',
            'birthdate.after' => 'The birthdate must be after January 1, 1900.',
            'birthdate.before_or_equal' => 'The applicant must be at least 18 years old.',
            'date_hired.before_or_equal' => 'The date hired cannot be in the future.',
        ];
    }
}
