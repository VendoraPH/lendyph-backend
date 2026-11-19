<?php

namespace App\Http\Controllers\Api\Branches;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\ApproveInterbranchTransferRequest;
use App\Http\Requests\Branch\BranchCountsRequest;
use App\Http\Requests\Branch\CancelInterbranchTransferRequest;
use App\Http\Requests\Branch\InterbranchTransferRequest;
use App\Http\Requests\Branch\ListInterbranchTransfersRequest;
use App\Http\Requests\Branch\ReceiveInterbranchTransferRequest;
use App\Http\Requests\Branch\RejectInterbranchTransferRequest;
use App\Http\Requests\Branch\ReverseInterbranchTransferRequest;
use App\Http\Requests\Branch\StoreInterbranchTransferRequest;
use App\Http\Resources\InterbranchTransferResource;
use App\Models\InterbranchTransfer;
use App\Services\Branches\InterbranchInTransitReport;
use App\Services\Branches\InterbranchTransfers;
use App\Services\Savings\IdempotentWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Interbranch transfers (Phase 6 MB-BE5, multi-branch decision 9). A transfer
 * whose source and destination the caller can't see answers 404. Approve,
 * receive and reverse post journals and need an `Idempotency-Key` header (a
 * UUID): a retry with the same key gets the first answer and posts nothing.
 * Amounts are integer centavos.
 */
class InterbranchTransferController extends Controller
{
    private const CONFLICT = 'Another change to this transfer was saved at the same moment. Reload it and try again.';

    public function __construct(
        private InterbranchTransfers $transfers,
        private InterbranchInTransitReport $report,
        private IdempotentWrite $idempotent,
    ) {}

    #[OA\Get(
        path: '/api/interbranch-transfers',
        summary: 'Interbranch transfers in the caller\'s branches',
        description: 'Newest first. Filters: status, branch_id (source or destination; another branch answers 403), per_page (at most 100).',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Paginated transfers'),
            new OA\Response(response: 403, description: 'Needs interbranch_transfers:view'),
        ],
    )]
    public function index(ListInterbranchTransfersRequest $request): AnonymousResourceCollection
    {
        $branchId = $request->validated('branch_id');

        $page = InterbranchTransfer::query()
            ->visibleTo($request->user())
            ->withDetails()
            ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($branchId !== null, fn ($q) => $q->where(fn ($q) => $q
                ->where('source_branch_id', (int) $branchId)
                ->orWhere('destination_branch_id', (int) $branchId)))
            ->orderByDesc('id')
            ->paginate(min((int) ($request->validated('per_page') ?? 25), 100));

        return InterbranchTransferResource::collection($page);
    }

    #[OA\Post(
        path: '/api/interbranch-transfers',
        summary: 'Request a transfer from one branch to another',
        description: 'Nothing posts until someone other than the maker approves it. `warnings` lists, in words, a source money account the transfer would take below ₱0.00 in its branch\'s books; it never refuses.',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['source_branch_id', 'destination_branch_id', 'from_account_id', 'to_account_id', 'amount', 'purpose'],
            properties: [
                new OA\Property(property: 'source_branch_id', type: 'integer', description: 'One of the maker\'s branches'),
                new OA\Property(property: 'destination_branch_id', type: 'integer'),
                new OA\Property(property: 'from_account_id', type: 'integer', description: 'A money account'),
                new OA\Property(property: 'to_account_id', type: 'integer', description: 'A money account'),
                new OA\Property(property: 'amount', type: 'integer', description: 'Centavos'),
                new OA\Property(property: 'purpose', type: 'string', maxLength: 500),
                new OA\Property(property: 'reference', type: 'string', maxLength: 64, nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: '{data, warnings}'),
            new OA\Response(response: 403, description: 'Needs interbranch_transfers:create'),
            new OA\Response(response: 422, description: 'In words: a closed branch, the same branch twice, or not a money account'),
        ],
    )]
    public function store(StoreInterbranchTransferRequest $request): JsonResponse
    {
        [$transfer, $warnings] = $this->transfers->create($request->validated(), $request->user());

        return $this->respond($transfer, $warnings, 201);
    }

    #[OA\Get(
        path: '/api/interbranch-transfers/{interbranchTransfer}',
        summary: 'One transfer',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'interbranchTransfer', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: '{data}'),
            new OA\Response(response: 404, description: 'Not found, or neither branch is the caller\'s'),
        ],
    )]
    public function show(InterbranchTransferRequest $request, InterbranchTransfer $interbranchTransfer): JsonResponse
    {
        return $this->respond($interbranchTransfer);
    }

    #[OA\Post(
        path: '/api/interbranch-transfers/{interbranchTransfer}/approve',
        summary: 'Approve and send: Dr 1190 / Cr the money account, in the source branch',
        description: 'Never by the maker, super_admin included (403). Needs access to the source branch. Idempotency-Key required.',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'interbranchTransfer', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: '{data, warnings}'),
            new OA\Response(response: 403, description: 'The maker, no access to the source branch, or missing interbranch_transfers:approve'),
            new OA\Response(response: 409, description: 'No longer waiting for approval'),
            new OA\Response(response: 422, description: 'In words: a closed period (errors.date), a closed branch, interbranch clearing not mapped'),
        ],
    )]
    public function approve(ApproveInterbranchTransferRequest $request, InterbranchTransfer $interbranchTransfer): JsonResponse
    {
        return $this->idempotent->run($request, function () use ($request, $interbranchTransfer): JsonResponse {
            [$transfer, $warnings] = $this->transfers->approve($interbranchTransfer, $request->user());

            return $this->respond($transfer, $warnings);
        }, self::CONFLICT);
    }

    #[OA\Post(
        path: '/api/interbranch-transfers/{interbranchTransfer}/receive',
        summary: 'Receive: Dr the money account / Cr 1190, in the destination branch',
        description: 'Needs access to the destination branch. Idempotency-Key required.',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'interbranchTransfer', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: '{data}'),
            new OA\Response(response: 403, description: 'No access to the destination branch, or missing interbranch_transfers:receive'),
            new OA\Response(response: 409, description: 'Not in transit'),
            new OA\Response(response: 422, description: 'In words: a closed period (errors.date)'),
        ],
    )]
    public function receive(ReceiveInterbranchTransferRequest $request, InterbranchTransfer $interbranchTransfer): JsonResponse
    {
        return $this->idempotent->run(
            $request,
            fn (): JsonResponse => $this->respond($this->transfers->receive($interbranchTransfer, $request->user())),
            self::CONFLICT,
        );
    }

    #[OA\Post(
        path: '/api/interbranch-transfers/{interbranchTransfer}/reverse',
        summary: 'Reverse a transfer that has posted',
        description: 'Posts a linked reversal of each leg (the receipt first), dated today. Needs access to the source branch, and to the destination once received. Idempotency-Key required.',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'interbranchTransfer', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 300)])),
        responses: [
            new OA\Response(response: 200, description: '{data}'),
            new OA\Response(response: 403, description: 'No access to a branch it posts in, or missing interbranch_transfers:approve'),
            new OA\Response(response: 409, description: 'Not in transit or received'),
            new OA\Response(response: 422, description: 'In words: a closed period (errors.date)'),
        ],
    )]
    public function reverse(ReverseInterbranchTransferRequest $request, InterbranchTransfer $interbranchTransfer): JsonResponse
    {
        return $this->idempotent->run(
            $request,
            fn (): JsonResponse => $this->respond($this->transfers->reverse($interbranchTransfer, $request->user(), (string) $request->validated('reason'))),
            self::CONFLICT,
        );
    }

    #[OA\Post(
        path: '/api/interbranch-transfers/{interbranchTransfer}/reject',
        summary: 'Reject a pending transfer',
        description: 'By a checker other than the maker, with a note. Nothing was posted.',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'interbranchTransfer', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['note'], properties: [new OA\Property(property: 'note', type: 'string', maxLength: 500)])),
        responses: [
            new OA\Response(response: 200, description: '{data}'),
            new OA\Response(response: 403, description: 'The maker, or missing interbranch_transfers:approve'),
            new OA\Response(response: 409, description: 'No longer waiting for approval'),
        ],
    )]
    public function reject(RejectInterbranchTransferRequest $request, InterbranchTransfer $interbranchTransfer): JsonResponse
    {
        return $this->respond($this->transfers->reject($interbranchTransfer, $request->user(), (string) $request->validated('note')));
    }

    #[OA\Post(
        path: '/api/interbranch-transfers/{interbranchTransfer}/cancel',
        summary: 'Cancel a pending transfer',
        description: 'Only by its maker. Nothing was posted.',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'interbranchTransfer', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: '{data}'),
            new OA\Response(response: 403, description: 'Not the maker'),
            new OA\Response(response: 409, description: 'No longer waiting for approval'),
        ],
    )]
    public function cancel(CancelInterbranchTransferRequest $request, InterbranchTransfer $interbranchTransfer): JsonResponse
    {
        return $this->respond($this->transfers->cancel($interbranchTransfer, $request->user()));
    }

    #[OA\Get(
        path: '/api/interbranch-transfers/in-transit',
        summary: 'Transfers in transit and each branch\'s interbranch clearing balance',
        description: 'Oldest first, each with its age in days. Branch balances (centavos) add up to total_clearing_balance; difference (clearing less in transit) is for branches:view_all only.',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: '{data: {as_of, clearing_account, transfers, branches, total_clearing_balance, total_in_transit, difference}}'),
            new OA\Response(response: 403, description: 'Needs interbranch_transfers:view'),
        ],
    )]
    public function inTransit(InterbranchTransferRequest $request): JsonResponse
    {
        $report = $this->report->build($request->user());

        return response()->json(['data' => [
            ...$report,
            'transfers' => InterbranchTransferResource::collection($report['transfers'])->resolve($request),
        ]]);
    }

    #[OA\Get(
        path: '/api/branch-counts',
        summary: 'The branch dashboard counts',
        description: 'Pending and in-transit transfers (null without interbranch_transfers:view), suspended branches, and branches with a reconciliation not yet reconciled (null without accounting:reconcile), all in the caller\'s branches.',
        tags: ['Branches'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: '{data: {pending_transfers, in_transit_transfers, suspended_branches, unreconciled_branches}}'),
            new OA\Response(response: 403, description: 'Needs dashboard:analytics'),
        ],
    )]
    public function counts(BranchCountsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->report->counts($request->user())]);
    }

    /** @param list<string>|null $warnings */
    private function respond(InterbranchTransfer $transfer, ?array $warnings = null, int $status = 200): JsonResponse
    {
        $transfer = InterbranchTransfer::query()->withDetails()->findOrFail($transfer->id);
        $body = ['data' => (new InterbranchTransferResource($transfer))->resolve(request())];

        if ($warnings !== null) {
            $body['warnings'] = $warnings;
        }

        return response()->json($body, $status);
    }
}
