<?php

namespace App\Http\Requests\Role;

use App\Models\Role;
use App\Rules\UniqueRoleDisplayName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRoleRequest extends FormRequest
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
        $roleId = $this->route('role')?->id;

        return [
            'name' => [
                'sometimes',
                'string',
                'max:100',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('roles', 'name')->ignore($roleId),
            ],
            // No control, zero-width or bidi characters: they let two labels
            // look identical in the role picker.
            'display_name' => ['sometimes', 'nullable', 'string', 'max:100', 'not_regex:/[\p{Cc}\p{Cf}]/u', new UniqueRoleDisplayName($roleId)],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'The role name must be lowercase letters, numbers, and underscores.',
        ];
    }

    /**
     * System roles cannot be renamed — their name is the identifier used in
     * permission checks throughout the application.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var Role|null $role */
                $role = $this->route('role');
                if (! $role instanceof Role) {
                    return;
                }

                if ($role->is_system && $this->filled('name') && $this->input('name') !== $role->name) {
                    $validator->errors()->add(
                        'name',
                        "System role '{$role->name}' cannot be renamed."
                    );
                }

                // Only a super_admin may relabel super_admin. Otherwise a
                // client admin could label it "Admin" (and admin something
                // else), and a platform super_admin picking "Admin" for a user
                // would hand out the Gate::before bypass.
                if ($role->name === 'super_admin'
                    && $this->filled('display_name')
                    && $this->input('display_name') !== $role->display_name
                    && ! $this->user()->hasRole('super_admin')) {
                    $validator->errors()->add(
                        'display_name',
                        'Only a super admin can change the super_admin role\'s display name.'
                    );
                }
            },
        ];
    }
}
