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
        throw (new ModelNotFoundException)->setModel(
            User::class,
            array_filter([$this->route('user')?->getKey()]),
        );
    }
}
