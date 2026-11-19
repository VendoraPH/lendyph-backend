<?php

namespace App\Http\Controllers\Api\Amla;

use App\Http\Controllers\Controller;
use App\Http\Requests\Amla\DecideAmlaChangeRequestRequest;
use App\Http\Requests\Amla\RejectAmlaChangeRequestRequest;
use App\Http\Requests\Amla\StoreAmlaChangeRequestRequest;
use App\Http\Resources\Amla\AmlaPresenter;
use App\Models\AmlaChangeRequest;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\ChangeRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Maker-checker changes to the AMLA settings and calendar (AMLA design
 * §4.13). Every endpoint needs `amla:settings` and `branches:view_all`. A
 * change applies only when a second holder approves it; the maker can't
 * approve or reject it, only withdraw it. Each step writes the AMLA log.
 *
 * A request is looked up by id only after the permission check (no route
 * model binding), so a caller without access gets the same 403 for an id
 * that exists and one that doesn't.
 */
class AmlaChangeRequestController extends Controller
{
    public function __construct(private readonly AmlaAccess $access, private readonly ChangeRequests $changes) {}

    #[OA\Get(
        path: '/api/amla/change-requests',
        summary: 'AMLA change requests',
        description: 'Needs `amla:settings` and `branches:view_all`. Newest first, 25 a page. Filters: `status` (pending, approved, rejected, withdrawn), `type` (settings, calendar_add, calendar_remove, calendar_confirm_year).',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The requests'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->access->authorizeHeadOffice($request->user(), AmlaAccess::SETTINGS);

        $filters = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(AmlaChangeRequest::STATUSES)],
            'type' => ['sometimes', 'string', Rule::in(AmlaChangeRequest::TYPES)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $page = AmlaChangeRequest::query()
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->with('requester', 'decider')
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (AmlaChangeRequest $change) => AmlaPresenter::changeRequest($change))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    #[OA\Get(
        path: '/api/amla/change-requests/{id}',
        summary: 'One AMLA change request',
        description: 'Needs `amla:settings` and `branches:view_all`.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The request'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function show(Request $request, int $id): JsonResponse
    {
        $this->access->authorizeHeadOffice($request->user(), AmlaAccess::SETTINGS);

        return $this->respond(AmlaChangeRequest::query()->findOrFail($id));
    }

    #[OA\Post(
        path: '/api/amla/change-requests',
        summary: 'Ask for a change to the AMLA settings or calendar',
        description: 'Needs `amla:settings` and `branches:view_all`. `type` and `reason`, plus: settings → `values` (partial), `verified` (key → source), `to_verify` (keys); calendar_add → `date`, `kind` (holiday, amlc_non_reporting, facility_suspension), `name`, `source`; calendar_remove → `entry_id`; calendar_confirm_year → `year`. Nothing applies until another holder approves. One request waits per target (422 in words); a change that changes nothing is refused (422).',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'The request, pending'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words'), new OA\Response(response: 422, description: 'Invalid, already waiting, or no change, in words')],
    )]
    public function store(StoreAmlaChangeRequestRequest $request): JsonResponse
    {
        $change = $this->changes->ask($request->user(), $request->validated());

        return $this->respond($change, 'The change is waiting for another AMLA settings holder to approve it.', 201);
    }

    #[OA\Post(
        path: '/api/amla/change-requests/{id}/approve',
        summary: 'Approve and apply an AMLA change',
        description: 'Needs `amla:settings` and `branches:view_all`, and not the maker (403). Optional `note`. A settings change adds the next settings version, in force from now; a calendar change applies at once. 409 when it is no longer pending or the settings moved on.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Approved'), new OA\Response(response: 403, description: 'Missing permission, head-office access, or the maker'), new OA\Response(response: 409, description: 'No longer pending, or the settings moved on')],
    )]
    public function approve(DecideAmlaChangeRequestRequest $request, int $id): JsonResponse
    {
        $change = $this->changes->approve($request->user(), AmlaChangeRequest::query()->findOrFail($id), $request->validated('note'));

        return $this->respond($change, 'The change is approved and in force.');
    }

    #[OA\Post(
        path: '/api/amla/change-requests/{id}/reject',
        summary: 'Reject an AMLA change',
        description: 'Needs `amla:settings` and `branches:view_all`, and not the maker (403: the maker withdraws). `note` required. 409 when it is no longer pending.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Rejected'), new OA\Response(response: 403, description: 'Missing permission, head-office access, or the maker'), new OA\Response(response: 409, description: 'No longer pending'), new OA\Response(response: 422, description: 'No note')],
    )]
    public function reject(RejectAmlaChangeRequestRequest $request, int $id): JsonResponse
    {
        $change = $this->changes->reject($request->user(), AmlaChangeRequest::query()->findOrFail($id), (string) $request->validated('note'));

        return $this->respond($change, 'The change is rejected.');
    }

    #[OA\Post(
        path: '/api/amla/change-requests/{id}/withdraw',
        summary: 'Withdraw your own AMLA change',
        description: 'Needs `amla:settings` and `branches:view_all`; only the maker (403). Optional `note`. 409 when it is no longer pending.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Withdrawn'), new OA\Response(response: 403, description: 'Missing permission, head-office access, or not the maker'), new OA\Response(response: 409, description: 'No longer pending')],
    )]
    public function withdraw(DecideAmlaChangeRequestRequest $request, int $id): JsonResponse
    {
        $change = $this->changes->withdraw($request->user(), AmlaChangeRequest::query()->findOrFail($id), $request->validated('note'));

        return $this->respond($change, 'The change is withdrawn.');
    }

    private function respond(AmlaChangeRequest $change, ?string $message = null, int $status = 200): JsonResponse
    {
        $body = ['success' => true, 'data' => AmlaPresenter::changeRequest($change->fresh(['requester', 'decider']))];

        if ($message !== null) {
            $body = ['success' => true, 'message' => $message, 'data' => $body['data']];
        }

        return response()->json($body, $status);
    }
}
