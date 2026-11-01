<?php

namespace App\Http\Requests\Role;

use App\Rules\UniqueRoleDisplayName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings:update');
    }

    /**
     * Trim display_name before validation so the uniqueness and length checks
     * see what is stored.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('display_name'))) {
            $this->merge(['display_name' => trim($this->input('display_name'))]);
        }
    }

    public function rules(): array
    {
        return [
            // Role name is used as the identifier in permission checks,
            // so enforce snake_case and uniqueness.
            'name' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('roles', 'name'),
            ],
            // No control, zero-width or bidi characters: they let two labels
            // look identical in the role picker.
            'display_name' => ['sometimes', 'nullable', 'string', 'max:100', 'not_regex:/[\p{Cc}\p{Cf}]/u', new UniqueRoleDisplayName],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'The role name must be lowercase letters, numbers, and underscores (e.g., branch_manager).',
        ];
    }
}
