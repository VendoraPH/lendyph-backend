<?php

namespace App\Http\Requests\Role;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings:update');
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
            },
        ];
    }
}
