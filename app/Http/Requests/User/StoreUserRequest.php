<?php

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\ResolvesBranchAssignment;
use App\Rules\AssignableRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    /**
     * Both `branch_id` and `branch_ids` are accepted; see the trait for which
     * wins when a body carries both, and for which id lands in the column.
     */
    use ResolvesBranchAssignment;

    public function authorize(): bool
    {
        return $this->user()->can('users:create');
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'alpha_dash', 'max:50', 'unique:users'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'mobile_number' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            /**
             * `required` became `required_without` so the multi-branch client
             * can post `branch_ids` alone. Every client that predates it still
             * sends `branch_id` and is unaffected; a body carrying neither is
             * refused exactly as before, because a user with no branch at all
             * is not a shape this application has ever produced and the old
             * frontend's `User.branch` is not optional.
             *
             * `nullable` only takes effect once `branch_ids` is present — the
             * `required_without` rule is implicit and still fires on an
             * explicit `branch_id: null` with no array beside it. It is there
             * so a client that sends both, with the legacy field blanked, is
             * not failed on a value the array has already overruled.
             */
            'branch_id' => ['required_without:branch_ids', 'nullable', 'exists:branches,id'],
            'branch_ids' => ['required_without:branch_id', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
            'role' => ['required', 'string', 'exists:roles,name', new AssignableRole($this->user())],
        ];
    }
}
