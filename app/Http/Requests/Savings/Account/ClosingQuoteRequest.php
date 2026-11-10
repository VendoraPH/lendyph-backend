<?php

namespace App\Http\Requests\Savings\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /savings/accounts/{account}/closing-quote` (savings:close): what
 * closing on `date` would credit and pay. The date is optional (today by
 * default) and takes the close's own rule: today or yesterday.
 */
class ClosingQuoteRequest extends FormRequest
{
    use AuthorizesSavingsRecord;

    public function authorize(): bool
    {
        return $this->authorizeRecord('savings:close', 'account', 'account');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['date' => ['nullable', ...array_slice($this->businessDate(), 1)]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->dateMessages('date', 'closing date');
    }
}
