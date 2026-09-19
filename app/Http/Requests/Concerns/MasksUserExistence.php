<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Answer an unauthorised caller with the SAME 404 a missing user id produces.
 *
 * Shared by every request that binds `{user}`: show, update, deactivate,
 * reactivate and reset-password. They have to agree — hardening one endpoint
 * while its four siblings still answer 403 does not close the oracle, it just
 * moves it to the next URL in the list.
 *
 * There are TWO ways to be refused across this family, and both have to answer
 * alike. `failedAuthorization()` covers the first — the caller lacks the
 * endpoint's permission. `denyAsMissingUser()` covers the second: an `after()`
 * hook deciding the TARGET is off-limits, which today means only the
 * `User::canManageAccount()` super_admin boundary. Those were written as
 * `$validator->errors()->add('user', ...)`, so they surfaced through
 * `FormRequest::failedValidation()` as a 422 and never reached
 * `failedAuthorization()` at all — which is how they survived the first pass
 * at this oracle.
 */
trait MasksUserExistence
{
    /**
     * `{user}` is resolved by implicit route-model binding, which runs before
     * the request is authorised. That split the outcomes for a caller without
     * the endpoint's permission into two distinguishable answers:
     *
     *   GET /api/users/4          -> 403 "This action is unauthorized."
     *   GET /api/users/99999      -> 404 "No query results for model [App\Models\User] 99999"
     *
     * which is a user-existence oracle for anyone holding any valid token. The
     * ids are sequential, so walking them enumerates the organisation's entire
     * staff list — how many accounts exist and which ids are live — from an
     * endpoint the caller is not allowed to use at all.
     *
     * Throwing the binding's own exception rather than `abort(404)` is what
     * makes the two indistinguishable: the handler turns ModelNotFoundException
     * into a NotFoundHttpException carrying this exact message, so the status,
     * the body and the shape all match byte for byte. An `abort(404)` here
     * would answer `{"message": ""}` and simply move the oracle.
     *
     * It relies on stock Laravel 404 rendering, which is why `withExceptions`
     * in bootstrap/app.php is deliberately left empty. A custom renderer for
     * NotFoundHttpException would have to reproduce this byte for byte or it
     * re-opens the hole.
     *
     * A caller who DOES hold the permission still gets the honest 404 from the
     * binding — they are allowed to know an id does not exist.
     */
    protected function failedAuthorization(): never
    {
        $this->refuseAsMissingUser();
    }

    /**
     * The same 404, for a refusal that is NOT an authorisation failure.
     *
     * Call this from an `after()` hook when the caller holds the endpoint's
     * permission but may not use it against THIS account. A 422 saying "only a
     * super_admin can edit a super_admin" does not merely confirm the id is
     * live, it names the account as the platform's — a better answer than the
     * enumeration this trait exists to prevent.
     *
     * Deliberately a separate throw site rather than a call to
     * `failedAuthorization()`, even though the two must stay byte-identical on
     * the wire. They are different events: one is "you cannot use this
     * endpoint", the other is "not against this target", and only the first is
     * an access denial in the sense anything watching that method would mean.
     * The equivalence is held by test, not by delegation — UserManagementTest
     * and UserRouteEnumerationTest both compare each refusal against the 404 an
     * id that was never used produces.
     *
     * Throwing from inside an after-callback is also deliberate: the exception
     * leaves `Validator::passes()` immediately, so the caller is not handed the
     * rest of the error bag either. A body that answered a bare 404 for a
     * missing id but "404 plus your email is malformed" for a real one would be
     * the same oracle wearing a different status code.
     */
    protected function denyAsMissingUser(): never
    {
        $this->refuseAsMissingUser();
    }

    /**
     * The wire format, in one place.
     *
     * Both refusals above must be byte-identical to the 404 a never-used id
     * produces, and that is a property of the MESSAGE, not of the call site.
     * Keeping the two throws as separate methods preserves the distinction that
     * matters — one is an access denial, the other is not, and anything
     * watching `failedAuthorization()` should see only the first — while making
     * the format impossible to get right in one and wrong in the other.
     *
     * It was wrong in both. Echoing `$this->route('user')->getKey()` echoes the
     * RESOLVED model's key, which `getCasts()` has already normalised to an
     * integer. Laravel's own binding miss echoes the raw URL segment
     * (ImplicitRouteBinding::resolveForRoute -> `[$parameterValue]`), and
     * `{user}` compiles to `[^/]++` with no numeric constraint, while MySQL
     * resolves `where('id', '007')` numerically. So:
     *
     *     GET /api/users/007   user 7 exists  ->  "... [App\Models\User] 7"
     *     GET /api/users/007   user 7 missing ->  "... [App\Models\User] 007"
     *
     * One request per id, no `users:*` permission needed, repeatable, and it
     * walks the whole sequential range. Reproduced against a real server before
     * this fix. The routes also carry `whereNumber('user')` now, which is worth
     * having but is NOT the fix on its own: `007` is still `[0-9]+`.
     */
    private function refuseAsMissingUser(): never
    {
        // `originalParameters` is snapshotted in Route::bind() at match time,
        // before SubstituteBindings overwrites the parameter with the model,
        // so this is the segment the caller actually sent.
        $attempted = $this->route()?->originalParameter('user')
            ?? $this->route('user')?->getKey();

        throw (new ModelNotFoundException)->setModel(
            User::class,
            array_filter([$attempted], static fn ($value) => $value !== null && $value !== ''),
        );
    }
}
