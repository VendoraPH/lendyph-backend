<?php

namespace App\Http\Controllers\Api\Branches;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserBranchHistoryRequest;
use App\Models\User;
use App\Services\Users\UserBranchHistory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * A user's branch history (Phase 6 MB-BE6): assignments and primary-branch
 * changes, read from audit rows, newest first, in words.
 */
class UserBranchHistoryController extends Controller
{
    public function __construct(private UserBranchHistory $history) {}

    #[OA\Get(
        path: '/api/users/{user}/branch-history',
        summary: "A user's branch history",
        description: 'Branch assignments (`kind` branches: `from` and `to` are lists of branch names) and primary-branch changes (`kind` primary: `from` and `to` are a branch name or "Organisation-wide"; `from` is null when the user was created), read from audit rows, newest first, each with a `description` in words, `by` (the person, or "System") and `at`. Needs `users:view` and the caller\'s reach; otherwise 404, like the rest of user management.',
        tags: ['Users'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated history'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found, also without users:view or outside the caller\'s reach'),
        ],
    )]
    public function show(UserBranchHistoryRequest $request, User $user): AnonymousResourceCollection
    {
        return JsonResource::collection($this->history->for($user, (int) ($request->validated('per_page') ?? 25)));
    }
}
