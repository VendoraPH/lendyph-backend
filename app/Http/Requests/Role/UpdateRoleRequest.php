<?php

namespace App\Http\Requests\Role;

use App\Http\Requests\Concerns\GuardsRolePermissionGrants;
use App\Models\Role;
use App\Rules\ExactPermissionName;
use App\Rules\UniqueRoleDisplayName;
use App\Rules\ValidUtf8;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateRoleRequest extends FormRequest
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
        $roleId = $this->route('role')?->id;

        return [
            'name' => [
                'bail',
                'sometimes',
                'string',
                'max:100',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('roles', 'name')->ignore($roleId),
                // Nor may the key read as another role's display name.
                new UniqueRoleDisplayName($roleId),
            ],
            // Valid UTF-8 first (bail): a /u pattern never matches invalid
            // bytes. Then no character that lets two labels look identical in
            // the role picker (see UniqueRoleDisplayName::FORBIDDEN_CHARACTERS).
            'display_name' => ['bail', 'sometimes', 'nullable', 'string', new ValidUtf8, 'max:100', 'not_regex:'.UniqueRoleDisplayName::FORBIDDEN_CHARACTERS, new UniqueRoleDisplayName($roleId)],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', new ExactPermissionName],
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

                if (($role->is_system || in_array($role->name, Role::PROTECTED_KEYS, true))
                    && $this->filled('name') && $this->input('name') !== $role->name) {
                    $validator->errors()->add(
                        'name',
                        "System role '{$role->name}' cannot be renamed."
                    );
                }

                // Nor may a role take a protected key, even on a database
                // missing that row: the Gate::before bypass and every name
                // check key off it.
                if ($this->filled('name') && $this->input('name') !== $role->name
                    && in_array($this->input('name'), Role::PROTECTED_KEYS, true)) {
                    $validator->errors()->add(
                        'name',
                        "The role name {$this->input('name')} is reserved."
                    );
                }

                // Only a super_admin may relabel super_admin. Otherwise a
                // client admin could label it "Admin" (and admin something
                // else), and a platform super_admin picking "Admin" for a user
                // would hand out the Gate::before bypass. Clearing the label is
                // a change too; sending the stored one back is not.
                if ($role->name === 'super_admin'
                    && $this->has('display_name')
                    && self::effectiveLabel($role, $this->input('display_name')) !== self::effectiveLabel($role, $role->display_name)
                    && ! $this->user()->hasRole('super_admin')) {
                    $validator->errors()->add(
                        'display_name',
                        'Only a super admin can change the super_admin role\'s display name.'
                    );
                }

                $this->refuseUngrantablePermissions($validator, $role);
            },
        ];
    }

    /**
     * The label a role is shown by: a blank or missing label means the default,
     * the key in headline case ("Super Admin"), which is also what the role
     * editor shows, and sends back as `display_name: null`.
     */
    private static function effectiveLabel(Role $role, mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return Str::headline($role->name);
        }

        return is_string($value) ? trim($value) : $value;
    }
}
