<?php

namespace App\Http\Requests\Savings\Hold;

use App\Http\Requests\Savings\Account\AuthorizesSavingsRecord;
use Illuminate\Foundation\Http\FormRequest;

/** `POST /savings/holds/{hold}/release` (savings:transact), with a reason. */
class ReleaseSavingsHoldRequest extends FormRequest
{
    use AuthorizesSavingsRecord;

    public function authorize(): bool
    {
        return $this->authorizeRecord('savings:transact', 'hold', 'hold');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the hold is released.',
            'reason.string' => 'The reason must be text.',
            'reason.max' => 'The reason can be at most 500 characters.',
        ];
    }
}
