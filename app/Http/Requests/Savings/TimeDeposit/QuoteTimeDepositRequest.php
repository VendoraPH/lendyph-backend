<?php

namespace App\Http\Requests\Savings\TimeDeposit;

use Illuminate\Foundation\Http\FormRequest;

/** `POST /savings/time-deposits/quote`: the placement's figures, worked out by the server. Needs `savings:create`. */
class QuoteTimeDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('savings:create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return TimeDepositRules::quote($this->user());
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return TimeDepositRules::quoteMessages();
    }
}
