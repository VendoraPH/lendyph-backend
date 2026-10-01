<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ListStaffRequest;
use App\Http\Resources\StaffResource;
use App\Models\User;
use App\Services\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * The staff list behind the account-officer pickers (new loan, restructure,
 * loan detail).
 *
 * Those pickers used to read `GET /api/users`, which only admin and
 * super_admin may call, so every loan officer saw an empty list. This is the
 * narrow alternative: names and ids of active staff, for anyone who can create,
 * edit or restructure a loan. It is NOT a second way into user management —
 * see ListStaffRequest for the gate and StaffResource for why the row is two
 * keys.
 */
class StaffController extends Controller
{
    #[OA\Get(
        path: '/api/staff',
        summary: 'List active staff for account-officer pickers',
        description: 'Active users only, as `{id, full_name}` — the same `full_name` a loan returns for '
            .'`account_officer`. Allowed for any caller holding `loans:create`, `loans:update` or '
            .'`loans:restructure` (the new-loan, loan-detail and restructure pickers); this is not user '
            .'management and does not require `users:view`. `search` matches first name, last '
            .'name or "first last", never username or email. Ordered by first name, last name, then id. '
            .'`per_page` is clamped to 1..100.',
        tags: ['Staff'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Matches first name, last name, or "first last". Max 100 characters.', schema: new OA\Schema(type: 'string', maxLength: 100)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, minimum: 1, maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated active staff',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Staff')),
                    new OA\Property(property: 'links', type: 'object', properties: [
                        new OA\Property(property: 'first', type: 'string', nullable: true),
                        new OA\Property(property: 'last', type: 'string', nullable: true),
                        new OA\Property(property: 'prev', type: 'string', nullable: true),
                        new OA\Property(property: 'next', type: 'string', nullable: true),
                    ]),
                    new OA\Property(property: 'meta', type: 'object', properties: [
                        new OA\Property(property: 'current_page', type: 'integer'),
                        new OA\Property(property: 'from', type: 'integer', nullable: true),
                        new OA\Property(property: 'last_page', type: 'integer'),
                        new OA\Property(property: 'path', type: 'string'),
                        new OA\Property(property: 'per_page', type: 'integer'),
                        new OA\Property(property: 'to', type: 'integer', nullable: true),
                        new OA\Property(property: 'total', type: 'integer'),
                    ]),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Holds none of loans:create, loans:update or loans:restructure'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function index(ListStaffRequest $request): AnonymousResourceCollection
    {
        $search = $request->validated('search');

        $staff = User::query()
            ->active()
            // Only what StaffResource emits (full_name is built from the two
            // name columns), so nothing else is even read into memory.
            ->select(['id', 'first_name', 'last_name'])
            // Names only — deliberately NOT username or email. Searching a
            // field the response does not return turns the filter into an
            // oracle for it: `?search=gmail` would reveal who has a gmail
            // address to a caller who is not allowed to see addresses.
            ->when(filled($search), function (Builder $query) use ($search) {
                $like = LikePattern::contains($search);

                $query->where(fn (Builder $q) => $q
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]));
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            // Tiebreak on the key: two staff can share a name, and a page must
            // never repeat or skip one. See DeterministicPaginationTest.
            ->orderBy('id')
            ->paginate($request->perPage());

        return StaffResource::collection($staff);
    }
}
