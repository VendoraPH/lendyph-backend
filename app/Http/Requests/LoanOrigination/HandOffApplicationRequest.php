<?php

namespace App\Http\Requests\LoanOrigination;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /loan-origination/applications/{application}/submit-for-approval: the
 * hand-off. `version` is the application version the client read. The rest
 * is optional: the loan's start date (today by default), its account officer
 * (the application's assigned officer by default; null for none), its share
 * capital build-up (the product's minimum when the product requires one, else
 * none) and `force_new_member` to create the member even when a similar one
 * exists. Permissions are checked by the hand-off itself, in words
 * (ApplicationHandOff).
 */
class HandOffApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:0'],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'account_officer_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'scb_amount' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'force_new_member' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'version.required' => 'Refresh the application and try again.',
            'version.integer' => 'Refresh the application and try again.',
            'version.min' => 'Refresh the application and try again.',
            'start_date.date_format' => 'Enter the start date as a date, like 2026-11-19.',
            'account_officer_id.integer' => 'Choose an account officer from the list.',
            'scb_amount.numeric' => 'Enter the share capital build-up as an amount in pesos.',
            'scb_amount.decimal' => 'Enter the share capital build-up with at most two decimals.',
            'scb_amount.min' => 'The share capital build-up can\'t be below ₱0.00.',
        ];
    }

    /** @return array{version: int, start_date?: string, account_officer_id?: ?int, scb_amount?: string, force_new_member?: bool} */
    public function handOffInput(): array
    {
        $input = ['version' => (int) $this->validated('version')];

        foreach (['start_date', 'account_officer_id', 'scb_amount', 'force_new_member'] as $field) {
            if ($this->exists($field)) {
                $value = $this->validated($field);
                $input[$field] = match ($field) {
                    'account_officer_id' => $value === null ? null : (int) $value,
                    'scb_amount' => (string) $value,
                    'force_new_member' => (bool) $value,
                    default => $value,
                };
            }
        }

        return $input;
    }
}
