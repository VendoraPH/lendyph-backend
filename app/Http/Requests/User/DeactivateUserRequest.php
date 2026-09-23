<?php

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\MasksUserExistence;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Authorisation for `PATCH /api/users/{user}/deactivate`.
 *
 * Moved out of `UserController::deactivate()` for the reason given on
 * ShowUserRequest: `$this->authorize()` runs after route-model binding has
 * already distinguished a live id from a dead one.
 *
 * The super_admin guard used to stay in the controller, on the reasoning that
 * a 422 about the TARGET "tells a caller who already holds `users:delete`
 * nothing they could not learn from the 200 they are entitled to". That was
 * wrong in one specific way: the 200 here is a bare message, so it reveals
 * that the id is live and nothing else, while the 422 additionally named the
 * account as the platform's super_admin. Identifying that account is the
 * single most useful thing this route could leak.
 *
 * It now lives in `after()` alongside the identical guards on update and
 * reset-password, and answers the same 404 they do. Keeping it in the
 * controller would have left the oracle intact one URL over, which is the
 * failure mode `MasksUserExistence` is written against.
 */
class DeactivateUserRequest extends FormRequest
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
     * Two refusals, in this order.
     *
     * FIRST, the super_admin target. Deactivation revokes the target's tokens
     * on their next request, so a client admin could otherwise lock the
     * platform team out of their own deployment. Same boundary as editing or
     * resetting that account, and the same 404.
     *
     * SECOND, an account that is already inactive. That is the same no-op
     * ReactivateUserRequest closes, in the other direction:
     * `update(['status' => 'inactive'])` on an already-inactive account is
     * `fill()->save()` with nothing dirty — no `performUpdate()`, no
     * `updated_at`, no audit row — while the caller still got a 200 saying
     * "User deactivated successfully." So the route answered 200 for every
     * live id and 404 for every dead one while leaving no trace of having been
     * asked. After this, a 200 from here always corresponds to a real state
     * change, which is the property the audit trail assumes.
     *
     * THE ORDER IS LOAD-BEARING, for the reason set out at length on
     * ReactivateUserRequest::after(), with one extra turn of the screw here.
     * That route has no super_admin tier of its own and runs the guard only so
     * its 422 cannot compose with this family's 404s; this route HAS a tier,
     * and a status check ahead of it would punch straight through it. A
     * super_admin target that happened to be inactive would answer "This
     * account is already inactive." instead of the masked 404, while
     * `PUT /users/{id}` still answered the same id 404 — a pairing no ordinary
     * account can produce, so it names the platform's account, and reports its
     * status as a bonus. The guard therefore refuses first, and only a target
     * this caller may actually manage ever reaches the message below.
     *
     * THE TOKEN SWEEP, which this short-circuits, and which does NOT move here.
     *
     * `UserController::deactivate()` runs `$user->tokens()->delete()`
     * unconditionally, and on an already-inactive target that is a real DELETE
     * rather than a second no-op: EnsureUserIsActive revokes only the token
     * the current request arrived on, so an account whose status was flipped
     * outside this endpoint — direct SQL, a seed, a restore — can still be
     * holding other rows. Refusing before the controller leaves them in place.
     * That is deliberate:
     *
     *  1. THE ROWS ARE NOT A CREDENTIAL. EnsureUserIsActive re-reads `status`
     *     from the database on EVERY request and answers 403 whichever row was
     *     presented, then deletes that row on the way out. A stale token grants
     *     nothing and destroys itself the first time it is used. The sweep is
     *     hygiene, not an access control, and nothing turns on its timing.
     *  2. A 422 MUST NOT WRITE. No other refusal in this family touches the
     *     target — not the masked 404s in MasksUserExistence, not the no-op
     *     422s on update and reactivate. Keeping the sweep would make this the
     *     one route where a REFUSED request mutates the target's credentials,
     *     and invisibly: the only audit row a deactivation produces is
     *     Auditable's `updated` event for the status change,
     *     `tokens()->delete()` is a query-builder delete that fires no model
     *     events at all, and an already-inactive target has no status change to
     *     record. Anyone holding `users:delete` could delete another account's
     *     session rows, leave no trace anywhere, and be told there was nothing
     *     to do. An unaudited write behind "nothing changed" is a worse thing
     *     to own than the rows it tidies.
     *  3. IT IS THE OTHER HALF OF THE PROPERTY ABOVE. A 200 now always means a
     *     real state change; that is only worth anything if a non-200 means
     *     none. A 422 that deleted rows anyway would be answering about a
     *     change it had already made one layer down.
     *
     * The sweep stays exactly where it is, on the 200 path, so every genuine
     * deactivation still revokes every session in the same request. All it
     * stops covering is a status flip this endpoint did not perform — and it
     * was never the right cleanup for one. No supported path produces those
     * rows today: `UserController::deactivate()` is the only writer of
     * `users.status = 'inactive'` in the application, `PUT /users/{id}` cannot
     * set the column (UpdateUserRequest has no rule for it and the controller
     * fills from `safe()`), and AuthController::login refuses an inactive
     * account before it mints a token. If out-of-band flips ever become a real
     * operational fact, the instrument is a prune command, not a refusal with
     * a side effect.
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

                // First, and masking: see the ordering note above.
                if (! $actor->canManageAccount($target)) {
                    $this->denyAsMissingUser();
                }

                if ($target->status !== 'inactive') {
                    return;
                }

                $validator->errors()->add('changes', 'This account is already inactive.');
            },
        ];
    }
}
