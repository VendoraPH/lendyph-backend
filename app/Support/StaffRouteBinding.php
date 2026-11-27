<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Who a branch-scoped route binding is scoped for (portal design §0, finding 7).
 *
 * - Nobody signed in: unscoped. That is the signed `/files/*` links, whose
 *   signature is their authorisation, and the public registration uploads.
 * - A staff User: scoped by the model's own rule (branch access).
 * - Anyone else (a member's PortalUser): resolves nothing, so the route
 *   answers 404. Portal routes never use implicit binding; this keeps a
 *   non-staff caller from ever reaching a staff record through one.
 */
final class StaffRouteBinding
{
    /**
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @param  Closure(TBuilder, User): TBuilder  $forStaff
     * @return TBuilder
     */
    public static function scope(Builder $query, Closure $forStaff): Builder
    {
        $caller = request()->user();

        if ($caller === null) {
            return $query;
        }

        if (! $caller instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return $forStaff($query, $caller);
    }
}
