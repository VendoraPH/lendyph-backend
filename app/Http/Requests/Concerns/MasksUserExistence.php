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
        $this->recordRefusal();

        throw (new ModelNotFoundException)->setModel(
            User::class,
            array_filter([$this->route('user')?->getKey()]),
        );
    }

    /**
     * One `user_access_denied` row per refused request.
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
    private function recordRefusal(): void
    {
        $target = $this->route('user');
        $actor = $this->user();

        try {
            AuditLogService::log(
                action: 'user_access_denied',
                newValues: [
                    'attempted_user_id' => $target instanceof User ? $target->getKey() : $target,
                    'route' => $this->route()?->getName(),
                    'method' => $this->method(),
                ],
                description: 'Refused a user-management request from a caller without the permission it requires, '
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
