<?php

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\MasksUserExistence;
use App\Models\User;
use App\Rules\AssignableRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    /**
     * A caller without `users:update` is answered with the SAME 404 a missing
     * id produces, rather than a 403 that confirms the account exists. The
     * rationale — and why `abort(404)` will not do — lives on the trait.
     */
    use MasksUserExistence;

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
     * Guards about *who is being changed*, and about whether anything is being
     * changed at all. `AssignableRole` only knows which role is being handed
     * out; all three of these need the target record.
     *
     * 1. A super_admin's account is off-limits to everyone else — see
     *    `User::canManageAccount()`. Demoting the platform role would take the
     *    only account able to hand it back, and editing the record at all is a
     *    step towards taking it over. Refused as a 404 rather than a 422: see
     *    `denyAsMissingUser()`.
     * 2. Nobody changes their own role, super_admin included. The role boundary
     *    is only worth checking if it cannot be edited by the account it
     *    constrains — otherwise "promote self, act, demote self" is a legal
     *    sequence of individually-valid requests.
     * 3. A request that would change nothing is refused outright.
     *
     * A payload that repeats the role the target already holds is not a change
     * and is not rejected by (2) — the user form posts the whole record back,
     * so treating that as a role edit would break editing your own name and
     * email. It does count as "no change" for (3) if every other field matches
     * too.
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
                    $this->denyAsMissingUser();
                }

                $changesRole = $this->has('role')
                    && $this->input('role') !== $target->getRoleNames()->first();

                if ($changesRole) {
                    if ((int) $target->id === (int) $actor->id) {
                        $validator->errors()->add(
                            'role',
                            'You cannot change your own role. Another administrator has to do it for you.',
                        );
                    }

                    return;
                }

                if (! $this->changesAnyColumn($target)) {
                    $validator->errors()->add(
                        'changes',
                        'Nothing to update. Change at least one field before saving.',
                    );
                }
            },
        ];
    }

    /**
     * Would this payload actually write anything to the users row?
     *
     * Every rule above is `sometimes` (and `mobile_number` is `nullable`
     * without `required`), so `{}` validates, reaches this hook and used to
     * return 200 with the full `UserResource` — role name included. That made
     * `PUT /users/{id}` a free read of a record the same caller is 404'd on at
     * `GET /users/{id}`: `authorize()` here checks only `users:update`, and
     * nothing in the write path needs `users:view`. It also wrote nothing —
     * `update([])` is `fill([])->save()`, `isDirty()` is false, so there is no
     * `performUpdate()`, no `updated_at` bump and no audit row — which made the
     * probe invisible as well as free.
     *
     * Refusing the no-op is what closes it: reading the record now costs a real
     * modification, which is destructive, audited, and self-limiting.
     *
     * `fill()` on a CLONE, then `isDirty()`, rather than a hand-rolled
     * comparison. It is the identical test `Model::save()` applies a moment
     * later in the controller, so "we refused it" and "it would have written"
     * cannot drift apart: same `$fillable` filtering, same
     * `originalIsEquivalent()` type handling (`branch_id` arriving as the
     * string "3" against an integer 3 is NOT a change). The clone keeps the
     * bound instance the controller re-fills untouched.
     *
     * Columns come from `rules()` minus `role`, so a field added to the rule
     * set is covered here without a second list to remember. `role` is excluded
     * because it lives in a pivot table, not on the row — the caller handles it
     * separately above.
     */
    private function changesAnyColumn(User $target): bool
    {
        $columns = array_values(array_diff(array_keys($this->rules()), ['role']));

        return (clone $target)->fill($this->only($columns))->isDirty();
    }
}
