<?php

namespace App\Services\Portal;

use App\Models\PortalUser;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * The signed-in member, taken only from the portal token (portal design §4,
 * §5). Portal routes never take a member id from the path, query or body, and
 * use no implicit binding: a record is found through here, so another
 * member's id answers 404, never 403.
 */
final class MemberScope
{
    public function portalUser(): PortalUser
    {
        $user = Auth::guard('portal')->user();

        if (! $user instanceof PortalUser) {
            throw new LogicException('MemberScope is used only behind the signed-in portal stack.');
        }

        return $user;
    }

    public function borrowerId(): int
    {
        return (int) $this->portalUser()->borrower_id;
    }

    /**
     * The record with this id, only when it belongs to the signed-in member;
     * otherwise 404.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     */
    public function findOrFail(Builder $query, int|string $id, string $memberColumn = 'borrower_id'): Model
    {
        return $query->where($memberColumn, $this->borrowerId())->findOrFail($id);
    }
}
