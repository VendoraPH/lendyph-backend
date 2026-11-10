<?php

namespace App\Http\Requests\Savings\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /savings/accounts/{account}/status` (savings:close): freeze
 * (`frozen`), or unfreeze or reactivate (`active`), with a reason.
 */
class ChangeSavingsStatusRequest extends FormRequest
{
    use AuthorizesSavingsRecord;

    public function authorize(): bool
    {
        return $this->authorizeRecord('savings:close', 'account', 'account');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['frozen', 'active'])],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.required' => 'Choose to freeze the account or make it active.',
            'status.string' => 'Choose to freeze the account or make it active.',
            'status.in' => 'An account can be frozen or made active here. Dormancy and closing have their own steps.',
            'reason.required' => 'Say why.',
            'reason.string' => 'The reason must be text.',
            'reason.max' => 'The reason can be at most 500 characters.',
        ];
    }
}
