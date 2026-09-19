<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

/**
 * Answer an unauthorised caller with the SAME 404 a missing user id produces,
 * and leave a record that it happened.
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
     * The caller may not use this endpoint at all.
     */
    private const REFUSAL_ACCESS_DENIED = 'user_access_denied';

    /**
     * The caller holds the endpoint's permission but may not use it against
     * THIS account — today, only a non-super_admin acting on the super_admin.
     *
     * A separate action rather than reusing the one above, because the two are
     * different events and the distinction is the whole reason `denyAsMissingUser()`
     * exists as its own method. It also happens to be the higher-signal of the
     * two: an ordinary permission miss is someone with the wrong role clicking
     * a button, while a run of these is somebody working their way toward the
     * platform account. Reusing one action would have buried the second in the
     * first, and recording only `failedAuthorization()` — which is where this
     * started — would have left the more interesting event with no trace at all.
     */
    private const REFUSAL_TARGET_REFUSED = 'user_target_refused';

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
     *
     * The refusal is RECORDED before it is answered. Masking the oracle also
     * made a refused probe completely silent: the throw happens here, inside
     * the FormRequest, which is upstream of every AuditLogService call site in
     * the controllers and upstream of the Auditable trait — whose hooks are all
     * *model* events, and a refusal mutates no model. Twenty-five refused
     * probes wrote zero `audit_logs` rows, so an id sweep looked exactly like
     * no traffic at all. This method is the one place all five routes converge,
     * which is what makes a single write here cover the whole family.
     */
    protected function failedAuthorization(): never
    {
        $this->recordRefusal(self::REFUSAL_ACCESS_DENIED);
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
     * Deliberately a separate method rather than a call to
     * `failedAuthorization()`, even though the two are byte-identical on the
     * wire. They are different events: one is "you cannot use this endpoint",
     * the other is "not against this target", and only the first is an access
     * denial in the sense anything watching that method would mean. They now
     * record under DIFFERENT actions for the same reason — see
     * self::REFUSAL_TARGET_REFUSED.
     *
     * The wire equivalence is held by `refuseAsMissingUser()`, which both call,
     * and by test: UserManagementTest and UserRouteEnumerationTest compare each
     * refusal against the 404 an id that was never used produces.
     *
     * Throwing from inside an after-callback is also deliberate: the exception
     * leaves `Validator::passes()` immediately, so the caller is not handed the
     * rest of the error bag either. A body that answered a bare 404 for a
     * missing id but "404 plus your email is malformed" for a real one would be
     * the same oracle wearing a different status code.
     */
    protected function denyAsMissingUser(): never
    {
        $this->recordRefusal(self::REFUSAL_TARGET_REFUSED);
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

    /**
     * One row per refused request, under the action its caller passes.
     *
     * Both refusal paths record. `failedAuthorization()` uses
     * REFUSAL_ACCESS_DENIED, `denyAsMissingUser()` uses REFUSAL_TARGET_REFUSED.
     *
     * WHAT IT RECORDS. The actor and the attempted id, and neither identifies a
     * sweep on its own — a sweep is a run of these rows from one `user_id`
     * across a stretch of ids in a short window. Both halves are cheap to query
     * for: `action` and `created_at` are indexed, `user_id` is the foreign key.
     *
     * WHAT IT DOES NOT RECORD, deliberately: `auditable`. By the time this runs
     * `$this->route('user')` is already a bound User, so the target model IS
     * available and passing it would cost nothing — and it is exactly the wrong
     * thing to do here.
     *
     *  1. AuditLogController::index() loads the trail with `with('auditable')`
     *     and AuditLogResource::resolveAuditableLabel() renders the related
     *     model's `full_name`. Naming the target would therefore print every
     *     refusal row as "User #7 — <that staff member's name>" to anyone
     *     holding `audit_logs:view`: the oracle this trait exists to close,
     *     rebuilt inside the log and upgraded from an id to a name. No SEEDED
     *     role holds `audit_logs:view` without `users:view` today, but roles
     *     are editable from the UI, so that is one configuration away rather
     *     than a guarantee.
     *  2. `auditable` in this schema means "the record this row describes a
     *     change to". A refusal changes nothing, and it is a fact about the
     *     CALLER. Hanging it off the target would also bury that account's real
     *     history under other people's probes.
     *
     * The attempted id still goes into `new_values`, where it is the caller's
     * own URL echoed back rather than a join into `users`: an audit reader sees
     * the same integer either way, but it resolves to no name and the morph
     * index cannot be pivoted on.
     *
     * WHAT THIS CANNOT MASK, and does not pretend to: route-model binding runs
     * BEFORE authorisation, so a probe for an id that does not exist 404s in
     * SubstituteBindings and never reaches a FormRequest at all. A row here
     * therefore only ever exists for an id that does. That is a property of the
     * middleware order rather than of this log, it is only visible to someone
     * already holding `audit_logs:view`, and the whole point of the row is to
     * tell a defender which ids were probed.
     *
     * WHY NOT Gate::after(). Laravel 13.29 has no authorisation-denial event
     * (`Illuminate\Auth\Access\Events\` holds only GateEvaluated), and
     * `Gate::after()` is the nearest read-only hook — safe, since Gate::raw()
     * does `$result ??= $afterResult` and a callback returning null cannot
     * change a decision. It is still the wrong instrument: it fires on EVERY
     * ability check in the application, including the several dozen a single
     * response makes to build the permissions array on a UserResource, so it
     * would write rows for checks that refuse no request, flood the table, and
     * can re-enter through the `audit_logs:view` check on the audit endpoint
     * itself. It would also still miss the case it looks like it would catch:
     * the super_admin guard on PUT /users/{id} is a validation error, not a
     * gate call, so GateEvaluated reports `true` on exactly those requests.
     */
    private function recordRefusal(string $action): void
    {
        $target = $this->route('user');
        $actor = $this->user();

        try {
            AuditLogService::log(
                action: $action,
                newValues: [
                    'attempted_user_id' => $target instanceof User ? $target->getKey() : $target,
                    'route' => $this->route()?->getName(),
                    'method' => $this->method(),
                ],
                description: $action === self::REFUSAL_TARGET_REFUSED
                    ? 'Refused a user-management request whose caller holds the permission but may not use it '
                        .'against this account, and answered 404 so the target stays indistinguishable from an id '
                        .'that does not exist.'
                    : 'Refused a user-management request from a caller without the permission it requires, '
                        .'and answered 404 so the id stays indistinguishable from one that does not exist.',
                userId: $actor instanceof User ? $actor->getKey() : null,
            );
        } catch (Throwable $e) {
            /**
             * The refusal is the security control; the record of it must never
             * become a way to break it. A locked `audit_logs` table, a full
             * disk or a schema mid-migration has to still produce the same 404
             * every other refused probe gets — anything else is a NEW oracle,
             * and a worse one, because it would answer 500 for exactly the ids
             * that exist and 404 for the ones that do not.
             */
            report($e);
        }
    }
}
