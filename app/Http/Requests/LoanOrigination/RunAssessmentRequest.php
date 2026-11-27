<?php

namespace App\Http\Requests\LoanOrigination;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /loan-origination/applications/{application}/assessment/rerun: the
 * verified monthly income and debt payments `loan_origination:assess`
 * holders enter (origination decision 15). Each is optional: left out it
 * keeps the previous run's, null clears it. Pesos, at most two decimals,
 * never rounded. `start_date` is the loan's planned start date, today when
 * left out: the hand-off assesses again at the loan's own.
 */
class RunAssessmentRequest extends FormRequest
{
    public const REFUSAL = 'Running an assessment needs the permission to assess online applications.';

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('loan_origination:assess');
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(self::REFUSAL);
    }

    public function rules(): array
    {
        $money = ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'];

        return [
            'verified_monthly_income' => $money,
            'verified_debt_payments' => $money,
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $words = ['verified_monthly_income' => 'verified monthly income', 'verified_debt_payments' => 'verified monthly debt payments'];
        $messages = ['start_date.date_format' => 'Enter the start date as a date, like 2026-11-19.'];

        foreach ($words as $field => $label) {
            $messages["{$field}.numeric"] = "Enter the {$label} as an amount in pesos, like 25000.";
            $messages["{$field}.decimal"] = "Enter the {$label} with at most two decimals.";
            $messages["{$field}.min"] = "The {$label} can't be below ₱0.00.";
            $messages["{$field}.max"] = "The {$label} is too large.";
        }

        return $messages;
    }

    /** @return array{verified_monthly_income?: ?string, verified_debt_payments?: ?string, start_date?: ?string} */
    public function figures(): array
    {
        $out = [];

        foreach (['verified_monthly_income', 'verified_debt_payments', 'start_date'] as $field) {
            if ($this->exists($field)) {
                $value = $this->validated($field);
                $out[$field] = $value === null ? null : (string) $value;
            }
        }

        return $out;
    }
}
