<?php

namespace App\Http\Requests\Savings\TimeDeposit;

use Illuminate\Foundation\Http\FormRequest;

/** `GET /savings/time-deposits/{id}/pretermination-quote?date=`. Needs `savings:close`. */
class PreterminationQuoteRequest extends FormRequest
{
    use FindsTheTimeDepositFirst;

    public function authorize(): bool
    {
        return $this->mayActOnTheTimeDeposit('savings:close');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date.required' => 'Pick the date it would be broken.',
            'date.date_format' => 'The date must be written as YYYY-MM-DD.',
        ];
    }
}
