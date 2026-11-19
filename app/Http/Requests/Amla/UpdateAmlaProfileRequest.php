<?php

namespace App\Http\Requests\Amla;

use App\Models\AmlaProfile;
use App\Models\SavingsAccount;
use App\Services\Accounting\Money;
use App\Services\Amla\Foundation\AmlaAccess;
use Illuminate\Validation\Rule;

/**
 * PUT /amla/cdd/{members|walk-ins}/{id}: the AMLA profile's KYC answers,
 * partial. Needs `amla:cdd`. None of these is ever required on the member
 * forms or registration (AMLA design §3.3); they live only here.
 */
class UpdateAmlaProfileRequest extends AmlaFormRequest
{
    protected function permissions(): array
    {
        return [AmlaAccess::CDD];
    }

    protected function headOffice(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nationality' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:150'],
            'source_of_funds' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(SavingsAccount::SOURCES_OF_FUNDS))],
            'source_of_funds_other' => ['nullable', 'string', 'max:150', 'required_if:source_of_funds,other'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:255'],
            'expected_monthly_amount' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'expected_monthly_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'pep_status' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(AmlaProfile::PEP_STATUSES))],
            'pep_details' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'adverse_info' => ['sometimes', 'nullable', 'boolean'],
            'adverse_info_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nationality.regex' => 'Give the nationality as a two-letter country code, such as PH.',
            'source_of_funds.in' => 'Pick salary, business, remittance, pension, savings or other.',
            'source_of_funds_other.required_if' => 'Say what the other source of funds is.',
            'expected_monthly_amount.decimal' => 'Give the expected monthly amount in pesos with at most two decimals.',
            'pep_status.in' => 'Pick whether the person is a politically exposed person, a family member or close associate of one, or neither.',
        ];
    }

    /**
     * The sent answers as the profile stores them: trimmed text (blank is
     * null), the expected amount in centavos, whole numbers and a boolean.
     *
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        $answers = [];

        foreach ($this->validated() as $field => $value) {
            if (is_string($value)) {
                $value = trim($value) === '' ? null : trim($value);
            }

            match ($field) {
                'expected_monthly_amount' => $answers['expected_monthly_centavos'] = $value === null ? null : (int) Money::toCentavos((string) $value),
                'expected_monthly_count' => $answers[$field] = $value === null ? null : (int) $value,
                'adverse_info' => $answers[$field] = $value === null ? null : (bool) $value,
                default => $answers[$field] = $value,
            };
        }

        return $answers;
    }
}
