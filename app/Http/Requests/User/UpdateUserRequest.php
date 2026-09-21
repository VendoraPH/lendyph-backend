<?php

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\MasksUserExistence;
use App\Http\Requests\Concerns\ResolvesBranchAssignment;
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

    /**
     * Both `branch_id` and `branch_ids` are accepted; see the trait for which
     * wins when a body carries both, and for why an UNCHANGED `branch_id` is
     * read as a repost rather than as "narrow this user to that one branch".
     */
    use ResolvesBranchAssignment;

    /**
     * Rule keys that are not columns on `users`, and so cannot take part in the
     * `isDirty()` test in {@see self::changesAnyColumn()}.
     *
     * `role` lives in Spatie's `model_has_roles`; `branch_ids` in `branch_user`.
     * `branch_ids.*` is here because it is a KEY of `rules()` too — an array
     * element rule, never an attribute — and feeding it to `only()` would have
     * `fill()` interpret the dot as nesting.
     *
     * @var list<string>
     */
    private const NON_COLUMN_RULES = ['role', 'branch_ids', 'branch_ids.*'];

    /**
     * `users:view` as well as `users:update`, and the second one is not
     * redundant.
     *
     * `UserController::update()` answers 200 with the full UserResource —
     * `roles` included. `users:update` alone therefore bought a read of any
     * account this caller is 404'd from on `GET /users/{id}`, for the price of
     * one real edit. It also left a field-equality oracle behind: a one-key
     * body like `{"branch_id": 3}` answers 422 "nothing to update" when the
     * guess is right and 200 when it is wrong, writing nothing either way, so
     * a caller could read a column by guessing it.
     *
     * Requiring both closes the read and the oracle together, because both need
     * the same caller. Raised in security review.
     *
     * Costs nothing today: `users:*` appears exactly once in
     * RoleAndPermissionSeeder, in the full permission catalogue, so only admin
     * and super_admin hold any of them and both hold all of them. The split is
     * something the roles screen can create, not something that exists.
     */
    public function authorize(): bool
    {
        return $this->user()->can('users:update') && $this->user()->can('users:view');
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
            /**
             * `min:1` rather than allowing `[]`. Clearing every branch is not
             * an edit this endpoint has ever offered — `branch_id` is
             * `exists:branches,id` with no `nullable`, so it cannot be blanked
             * either — and a branchless user would render as a missing `branch`
             * in the old auth store. Unassigning wholesale can be designed
             * later; it is not a side effect of adding a second branch.
             */
            'branch_ids' => ['sometimes', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
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

                // Compares against the FIRST role only, which is exact for every
                // user this application can produce — `store` assigns one and
                // `update` syncs one. It is not exact for a target holding more
                // than one, which Spatie permits and a seeder, console command
                // or direct insert can create: `syncRoles()` is unconditionally
                // detach-then-assign, so repeating the first role reads as "no
                // change" here while the controller would in fact have DROPPED
                // the others. Deliberately not branched for — a guard for a
                // state nothing creates is a guard nothing exercises — but it
                // is the one place this check and `save()` disagree.
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

                if (! $this->changesAnyColumn($target) && ! $this->changesBranchAssignment($target)) {
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
     * Columns come from `rules()` minus {@see self::NON_COLUMN_RULES}, so a
     * field added to the rule set is covered here without a second list to
     * remember. What that mechanical derivation CANNOT do is notice that a new
     * rule key is not a column at all, which is the whole reason
     * `changesBranchAssignment()` exists next to it.
     */
    private function changesAnyColumn(User $target): bool
    {
        $columns = array_values(array_diff(array_keys($this->rules()), self::NON_COLUMN_RULES));

        return (clone $target)->fill($this->only($columns))->isDirty();
    }

    /**
     * Would this payload actually change which branches the user is in?
     *
     * `branch_ids` is a pivot, so it is invisible to everything above: `fill()`
     * drops it (not in `$fillable`, and not a column to begin with), `isDirty()`
     * is therefore false, and a payload whose only change is branches — the
     * single most obvious edit the new multi-branch screen makes — would be
     * refused 422 "Nothing to update" while writing nothing. That is the same
     * shape of bug the `role` pivot has, and it is why `role` is answered
     * separately in `after()` rather than folded into `changesAnyColumn()`.
     *
     * The comparison is order- and duplicate-INSENSITIVE, because `sync()` is:
     * `[1,2]`, `[2,1]` and `[1,1,2]` all leave the user in exactly the same two
     * branches, so none of them is a change, while `[1]` → `[1,2]` is. Sorting
     * both sides is what makes "the client dragged the chips into a different
     * order" stop being an edit, and it is why the legacy column is derived as
     * "keep the current value if it survives" rather than "first of the array"
     * — see ResolvesBranchAssignment.
     *
     * Reads the CURRENT rows straight from the pivot rather than from a loaded
     * relation, so a relation cached earlier in the request cannot answer this
     * with a stale set.
     */
    private function changesBranchAssignment(User $target): bool
    {
        $incoming = $this->branchAssignment($target);

        if ($incoming === null) {
            return false;
        }

        $current = $target->branches()->pluck('branches.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        sort($incoming);
        sort($current);

        return $incoming !== $current;
    }
}
