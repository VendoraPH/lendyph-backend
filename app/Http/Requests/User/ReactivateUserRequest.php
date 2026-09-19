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
     * Deliberately NOT masked as a 404 the way the super_admin refusal is:
     * "this account is already active" is honest feedback for a caller who is
     * allowed to be here, and unlike deactivation there is no super_admin tier
     * on this route to conceal (restoring access takes nothing away from the
     * account being acted on).
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $target = $this->route('user');

                if (! $target instanceof User || $target->status !== 'active') {
                    return;
                }

                $validator->errors()->add('changes', 'This account is already active.');
            },
        ];
    }
}
