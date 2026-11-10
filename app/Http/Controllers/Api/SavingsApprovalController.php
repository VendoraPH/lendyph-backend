<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\Approval\CancelSavingsApprovalRequest;
use App\Http\Requests\Savings\Approval\DecideSavingsApprovalRequest;
use App\Http\Requests\Savings\Approval\ListSavingsApprovalsRequest;
use App\Http\Requests\Savings\Approval\RejectSavingsApprovalRequest;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\TimeDeposit;
use App\Services\BranchAccess;
use App\Services\Savings\Api\AccountScope;
use App\Services\Savings\Api\ApprovalDesk;
use App\Services\Savings\Api\Presenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The savings approvals queue (maker-checker, design §2, slice S2): every
 * kind, from withdrawals and closes to time deposit payouts. Listing needs
 * only `savings:view`, so makers see their own requests. The maker can never
 * approve or reject their own request (403) but can cancel it (with
 * `savings:transact`); a
 * second checker above tier 2 must be someone else again. Another branch's
 * request answers 404.
 */
class SavingsApprovalController extends Controller
{
    public function __construct(
        private readonly AccountScope $scope,
        private readonly Presenter $presenter,
        private readonly ApprovalDesk $desk,
    ) {}

    #[OA\Get(
        path: '/api/savings/approvals',
        summary: 'The savings approvals queue',
        description: 'Newest first, in the caller\'s branches. `meta.stats` counts requests per status over every filter but `status`. Needs `savings:view` (not `savings:approve`): makers see their own requests here too.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: SavingsApproval::STATUSES)),
            new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: SavingsApproval::KINDS)),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'One page of requests'),
            new OA\Response(response: 403, description: 'Missing savings:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'An unknown filter value'),
        ],
    )]
    public function index(ListSavingsApprovalsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $user = $request->user();
        app(BranchAccess::class)->authorizeBranchFilter($user, $filters['branch_id'] ?? null);
        $branchId = $filters['branch_id'] ?? null;

        $filtered = fn (): Builder => $this->scope->approvals($user)
            ->when(isset($filters['kind']), fn ($q) => $q->where('kind', $filters['kind']))
            ->when($branchId !== null, fn ($q) => $q->where(fn (Builder $w) => $w
                ->whereIn('account_id', SavingsAccount::query()->where('branch_id', $branchId)->select('id'))
                ->orWhereIn('time_deposit_id', TimeDeposit::query()->where('branch_id', $branchId)->select('id'))));
        $matching = fn (): Builder => $filtered()->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']));

        $page = $matching()->orderByDesc('id')->paginate(Presenter::perPage($filters['per_page'] ?? null));

        return Presenter::page($page, $this->presenter->approvals(collect($page->items())), [
            'stats' => Presenter::stats($filtered(), 'status', SavingsApproval::STATUSES),
        ]);
    }

    #[OA\Post(
        path: '/api/savings/approvals/{approval}/approve',
        summary: 'Approve a savings request',
        description: 'Never by the maker, and never twice by one checker (403). Above tier 2 the first approval records the first checker and the request stays pending; the last approval carries it out, re-checked under the account lock (422 when the money is gone: it stays pending). 409 when it is no longer pending or its day has ended.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'approval', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The request, pending (first of two checkers) or approved'),
            new OA\Response(response: 403, description: 'Missing savings:approve, the maker, or the same checker again'),
            new OA\Response(response: 404, description: 'No such request in the caller\'s branches'),
            new OA\Response(response: 409, description: 'No longer pending, or expired'),
            new OA\Response(response: 422, description: 'It can no longer be carried out, in words'),
        ],
    )]
    public function approve(DecideSavingsApprovalRequest $request, int $approval): JsonResponse
    {
        /** @var SavingsApproval $found */
        $found = $request->record();

        return $this->answer($this->desk->approve($found, $request->user()));
    }

    #[OA\Post(
        path: '/api/savings/approvals/{approval}/reject',
        summary: 'Reject a savings request',
        description: 'By a checker other than the maker (the maker cancels instead), with a reason. 409 when it is no longer pending.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'approval', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
            new OA\Property(property: 'reason', type: 'string'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The rejected request'),
            new OA\Response(response: 403, description: 'Missing savings:approve, or the maker'),
            new OA\Response(response: 404, description: 'No such request in the caller\'s branches'),
            new OA\Response(response: 409, description: 'No longer pending, or expired'),
            new OA\Response(response: 422, description: 'No reason'),
        ],
    )]
    public function reject(RejectSavingsApprovalRequest $request, int $approval): JsonResponse
    {
        /** @var SavingsApproval $found */
        $found = $request->record();

        return $this->answer($this->desk->reject($found, $request->validated('reason'), $request->user()));
    }

    #[OA\Post(
        path: '/api/savings/approvals/{approval}/cancel',
        summary: 'Cancel your own savings request',
        description: 'Needs `savings:transact`, and only the maker may (403 for anyone else), with an optional reason. 409 when it is no longer pending.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'approval', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'reason', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The cancelled request'),
            new OA\Response(response: 403, description: 'Missing savings:transact, or not the maker'),
            new OA\Response(response: 404, description: 'No such request in the caller\'s branches'),
            new OA\Response(response: 409, description: 'No longer pending, or expired'),
        ],
    )]
    public function cancel(CancelSavingsApprovalRequest $request, int $approval): JsonResponse
    {
        /** @var SavingsApproval $found */
        $found = $request->record();

        return $this->answer($this->desk->cancel($found, $request->validated('reason'), $request->user()));
    }

    private function answer(SavingsApproval $approval): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->presenter->approval($approval->refresh())]);
    }
}
