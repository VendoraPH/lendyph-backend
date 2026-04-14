<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'mobile_number' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^(\+?\d{7,15}|0\d{9,10})$/',
            ],
        ];
    }

    /**
     * Accept full_name as a convenience input from clients that use a single name
     * field (e.g. the Settings Profile page). Split on the first whitespace;
     * remainder goes to last_name.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('full_name') && ! $this->has('first_name') && ! $this->has('last_name')) {
            $parts = preg_split('/\s+/', trim((string) $this->input('full_name')), 2);
            $this->merge([
                'first_name' => $parts[0] ?? '',
                'last_name' => $parts[1] ?? '',
            ]);
        }
    }
}
