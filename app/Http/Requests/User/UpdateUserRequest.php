<?php

namespace App\Http\Requests\User;

use App\Models\User;
use App\Rules\AssignableRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users:update');
    }

    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'username' => ['sometimes', 'string', 'alpha_dash', 'max:50', Rule::unique('users')->ignore($userId)],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
            'mobile_number' => ['nullable', 'string', 'max:20'],
            'branch_id' => ['sometimes', 'exists:branches,id'],
            'role' => ['sometimes', 'string', 'exists:roles,name', new AssignableRole($this->user())],
        ];
    }

    /**
     * Guards about *who is being changed*. `AssignableRole` only knows which
     * role is being handed out; these two need the target record.
     *
     * 1. A super_admin's account is off-limits to everyone else — see
     *    `User::canManageAccount()`. Demoting the platform role would take the
     *    only account able to hand it back, and editing the record at all is a
     *    step towards taking it over.
     * 2. Nobody changes their own role, super_admin included. The role boundary
     *    is only worth checking if it cannot be edited by the account it
     *    constrains — otherwise "promote self, act, demote self" is a legal
     *    sequence of individually-valid requests.
     *
     * A payload that repeats the role the target already holds is not a change
     * and is left alone — the user form posts the whole record back, so
     * rejecting it would break editing your own name and email.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $target = $this->route('user');
                $actor = $this->user();

                if (! $target instanceof User || ! $actor instanceof User) {
                    return;
                }

                if (! $actor->canManageAccount($target)) {
                    $validator->errors()->add(
                        'user',
                        'Only a super_admin can edit a super_admin account.',
                    );

                    return;
                }

                if (! $this->has('role') || $this->input('role') === $target->getRoleNames()->first()) {
                    return;
                }

                if ((int) $target->id === (int) $actor->id) {
                    $validator->errors()->add(
                        'role',
                        'You cannot change your own role. Another administrator has to do it for you.',
                    );
                }
            },
        ];
    }
}
