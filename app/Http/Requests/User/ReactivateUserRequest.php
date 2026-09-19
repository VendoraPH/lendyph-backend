<?php

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\MasksUserExistence;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Authorisation for `PATCH /api/users/{user}/reactivate`.
 *
 * Moved out of `UserController::reactivate()` for the reason given on
 * ShowUserRequest: `$this->authorize()` runs after route-model binding has
 * already distinguished a live id from a dead one.
 *
 * Same permission as deactivation, `users:delete`. Unlike deactivation there
 * is no super_admin guard on the target — restoring access takes nothing away
 * from the account being acted on — and this change does not add one.
 */
class ReactivateUserRequest extends FormRequest
{
    use MasksUserExistence;

    public function authorize(): bool
    {
        return $this->user()->can('users:delete');
    }

    /**
     * Nothing to validate — this request exists for its authorisation and the
     * hook below.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Refuse to "reactivate" an account that is already active.
     *
     * `update(['status' => 'active'])` on a live account is `fill()->save()`
     * with nothing dirty: no `performUpdate()`, no `updated_at`, no audit row.
     * The caller still got a 200 saying "User reactivated successfully.", so
     * the endpoint answered 200 for every live id and 404 for every dead one
     * while leaving no trace of having been asked — the same free, invisible
     * probe an empty-body `PUT /users/{id}` was, and untested because
     * `UserRouteEnumerationTest` seeds its fixture `inactive` precisely so the
     * success path is observable.
     *
     * After this, a 200 from this route always corresponds to a real state
     * change, which is the property the audit trail assumes.
     *
     * "This account is already active" is honest feedback for a caller who is
     * allowed to be here, so it is NOT masked as a 404 the way the super_admin
     * refusal is — restoring access takes nothing away from the account being
     * acted on.
     *
     * But it is only honest once the super_admin is out of the way first, and
     * that ordering is the whole point of the callback below. This route has no
     * super_admin tier of its own, while `update`, `deactivate` and
     * `reset-password` all answer 404 for "missing OR super_admin". Left
     * unguarded, the pair composes into exactly the oracle those 404s remove:
     *
     *     PUT /users/{id} {}  -> 404  AND  PATCH /users/{id}/reactivate -> 422
     *         => the id is the super_admin
     *     PUT {} -> 422        => live, and not the super_admin
     *     both  -> 404         => no such id
     *
     * Free, no write, no audit row. Raised in security review. So the target
     * guard runs first and masks, and only a target this caller may actually
     * manage ever reaches the already-active message.
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

                // First, and masking: see the composition note above.
                if (! $actor->canManageAccount($target)) {
                    $this->denyAsMissingUser();
                }

                if ($target->status !== 'active') {
                    return;
                }

                $validator->errors()->add('changes', 'This account is already active.');
            },
        ];
    }
}
