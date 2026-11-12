<?php

namespace App\Http\Requests\Savings\AutoCredit;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /savings/auto-credit/instructions` (savings:post): the account (in
 * the caller's branches, checked by the service), the amount, the schedule,
 * who authorised it and when.
 */
class StoreAutoCreditInstructionRequest extends FormRequest
{
    use AutoCreditInstructionRules;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('savings:post');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'integer', 'min:1'],
            ...$this->instructionRules(true),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...$this->instructionMessages(),
            'account_id.required' => 'Choose the savings account.',
            'account_id.integer' => 'Choose the savings account from the list.',
            'account_id.min' => 'Choose the savings account from the list.',
        ];
    }
}
