<?php

namespace App\Http\Requests\Role;

use App\Http\Requests\Concerns\GuardsRolePermissionGrants;
use App\Rules\UniqueRoleDisplayName;
use App\Rules\ValidUtf8;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRoleRequest extends FormRequest
{
    use GuardsRolePermissionGrants;

    public function authorize(): bool
    {
        return $this->user()->can('settings:update');
    }

    /**
     * Trim and NFC-normalise display_name before validation so the uniqueness,
     * length and character checks see what is stored. NFC turns a decomposed
     * accent (e + U+0301) into its precomposed form; marks with no
     * precomposed form stay and are refused. Invalid UTF-8 is left as sent,
     * for ValidUtf8 to refuse.
     */
    protected function prepareForValidation(): void
    {
        $composed = is_string($this->input('display_name'))
            ? UniqueRoleDisplayName::compose($this->input('display_name'))
            : null;

        if ($composed !== null) {
            $this->merge(['display_name' => $composed]);
        }
    }

    public function rules(): array
    {
        return [
            // Role name is used as the identifier in permission checks,
            // so enforce snake_case and uniqueness.
            'name' => [
                'bail',
                'required',
                'string',
                'max:100',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('roles', 'name'),
                // Nor may the key read as another role's display name.
                new UniqueRoleDisplayName,
            ],
            // Valid UTF-8 first (bail): a /u pattern never matches invalid
            // bytes. Then no character that lets two labels look identical in
            // the role picker (see UniqueRoleDisplayName::FORBIDDEN_CHARACTERS).
            'display_name' => ['bail', 'sometimes', 'nullable', 'string', new ValidUtf8, 'max:100', 'not_regex:'.UniqueRoleDisplayName::FORBIDDEN_CHARACTERS, new UniqueRoleDisplayName],
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

    /**
     * A new role may not carry a permission the creator doesn't hold
     * (GuardsRolePermissionGrants).
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->refuseUngrantablePermissions($validator, null),
        ];
    }
}
