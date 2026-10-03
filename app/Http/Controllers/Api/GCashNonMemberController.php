<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GCash\StoreGCashNonMemberRequest;
use App\Http\Requests\GCash\UpdateGCashNonMemberRequest;
use App\Http\Resources\GCashNonMemberResource;
use App\Models\GCashNonMember;
use App\Rules\UniqueWalkInIdNumber;
use App\Services\AuditLogService;
use App\Services\LikePattern;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class GCashNonMemberController extends Controller
{
    #[OA\Get(
        path: '/api/gcash/non-members',
        summary: 'List GCash walk-in (non-member) customers',
        tags: ['GCash'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'Matches name, mobile number or ID number.'),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated walk-in customers'),
            new OA\Response(response: 403, description: 'Missing gcash:view permission'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('gcash:view');

        // `min:1`: `?per_page=0` silently became the default page inside
        // paginate(), and a negative value reached MySQL as `offset` with no
        // `limit` — a 500. The same rule every other list validates.
        // `string`: `?search[]=x` arrived as an array and 500'd on concatenation.
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string'],
        ]);
        $perPage = (int) ($validated['per_page'] ?? 25);
        // Gated on filled(), not truthiness: `when()` skips a falsy value, so
        // `?search=0` was dropped and listed every walk-in.
        $search = $validated['search'] ?? null;

        $nonMembers = GCashNonMember::query()
            ->withCount('transactions')
            ->when(filled($search), function ($q) use ($search) {
                $like = LikePattern::contains($search);
                // ID numbers also match normalised, the way the duplicate rule
                // compares them, so `12345678` finds `1234-5678` and the reverse.
                // Skipped when nothing is left (a search of `---`), which would
                // otherwise match every walk-in.
                $normalised = UniqueWalkInIdNumber::normalise($search);
                $q->where(fn ($w) => $w
                    ->where('full_name', 'like', $like)
                    ->orWhere('mobile_number', 'like', $like)
                    ->orWhere('id_number', 'like', $like)
                    ->when($normalised !== '', fn ($w) => $w->orWhereRaw(
                        UniqueWalkInIdNumber::NORMALISED_COLUMN.' LIKE ?',
                        [LikePattern::contains($normalised)],
                    )));
            })
            ->orderBy('full_name')
            // Tiebreak on the key: walk-ins share names, and the picker drains
            // every page. See DeterministicPaginationTest.
            ->orderBy('id')
            ->paginate(min($perPage, 100));

        return GCashNonMemberResource::collection($nonMembers);
    }

    #[OA\Post(
        path: '/api/gcash/non-members',
        summary: 'Register a GCash walk-in customer',
        tags: ['GCash'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'Walk-in registered'),
            new OA\Response(response: 403, description: 'Missing gcash:transact permission'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreGCashNonMemberRequest $request): JsonResponse
    {
        $nonMember = GCashNonMember::create($request->validated());

        AuditLogService::log(
            action: 'created',
            auditable: $nonMember,
            newValues: $nonMember->toArray(),
            description: "GCash walk-in {$nonMember->full_name} registered",
        );

        return (new GCashNonMemberResource($nonMember->loadCount('transactions')))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/gcash/non-members/{id}',
        summary: 'Update a GCash walk-in customer',
        tags: ['GCash'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Walk-in updated'),
            new OA\Response(response: 403, description: 'Missing gcash:transact permission'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateGCashNonMemberRequest $request, GCashNonMember $nonMember): JsonResponse
    {
        $before = $nonMember->toArray();
        $nonMember->update($request->validated());

        AuditLogService::log(
            action: 'updated',
            auditable: $nonMember,
            oldValues: $before,
            newValues: $nonMember->toArray(),
            description: "GCash walk-in {$nonMember->full_name} updated",
        );

        return response()->json([
            'message' => 'Walk-in customer updated.',
            'data' => new GCashNonMemberResource($nonMember->loadCount('transactions')),
        ]);
    }

    /**
     * Soft delete, which is what the remove dialog promises: the walk-in leaves
     * the list, and the transactions recorded against them are kept. Hard
     * deleting would either destroy that history or — with the FK's restrict —
     * make anyone who ever transacted permanently unremovable, which the UI
     * gives no warning about.
     */
    #[OA\Delete(
        path: '/api/gcash/non-members/{id}',
        summary: 'Remove a GCash walk-in customer',
        tags: ['GCash'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Walk-in removed'),
            new OA\Response(response: 403, description: 'Missing gcash:transact permission'),
        ],
    )]
    public function destroy(GCashNonMember $nonMember): JsonResponse
    {
        $this->authorize('gcash:transact');

        $before = $nonMember->toArray();
        $name = $nonMember->full_name;
        $nonMember->delete();

        AuditLogService::log(
            action: 'deleted',
            auditable: $nonMember,
            oldValues: $before,
            description: "GCash walk-in {$name} removed",
        );

        return response()->json(['message' => 'Walk-in customer removed.']);
    }
}
