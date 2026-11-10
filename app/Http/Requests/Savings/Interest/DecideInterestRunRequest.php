<?php

namespace App\Http\Requests\Savings\Interest;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /savings/interest-runs/{run}/reject` and `/reverse`
 * (savings:approve): `{reason}`, which the run keeps and the audit trail
 * shows.
 */
class DecideInterestRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('savings:approve');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give a reason.',
            'reason.string' => 'Give the reason in words.',
            'reason.max' => 'The reason can be at most 500 characters.',
        ];
    }
}
