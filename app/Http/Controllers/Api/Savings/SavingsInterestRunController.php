<?php

namespace App\Http\Controllers\Api\Savings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\Interest\DecideInterestRunRequest;
use App\Http\Requests\Savings\Interest\InterestRunRequest;
use App\Http\Requests\Savings\Interest\ListInterestRunsRequest;
use App\Models\SavingsInterestRun;
use App\Models\SavingsProduct;
use App\Services\BranchAccess;
use App\Services\Savings\Interest\RunBuilder;
use App\Services\Savings\Interest\RunPoster;
use App\Services\Savings\Interest\RunPresenter;
use App\Services\Savings\Interest\RunReverser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Savings interest runs (design §2, §5): preview, one draft per branch,
 * approve or reject by a different user, post, and reverse the latest.
 * Every figure is the server's. Another branch's run answers 404.
 */
class SavingsInterestRunController extends Controller
{
    public function __construct(
        private readonly RunBuilder $builder,
        private readonly RunPoster $poster,
        private readonly RunReverser $reverser,
        private readonly RunPresenter $presenter,
        private readonly BranchAccess $branches,
    ) {}

    #[OA\Get(
        path: '/api/savings/interest-runs',
        summary: 'Savings interest runs',
        description: 'Newest period first, in the caller\'s branches. `meta.stats` counts runs per status under the product and branch filters (not the status filter).',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: SavingsInterestRun::STATUSES)),
            new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of runs'),
            new OA\Response(response: 403, description: 'Missing savings:view, or a branch_id outside the caller\'s branches'),
            new OA\Response(response: 422, description: 'An invalid filter'),
        ],
    )]
    public function index(ListInterestRunsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $user = $request->user();
        $this->branches->authorizeBranchFilter($user, $filters['branch_id'] ?? null);

        $base = $this->presenter->scope($user)
            ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id));

        $stats = array_fill_keys(SavingsInterestRun::STATUSES, 0);

        foreach ((clone $base)->toBase()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status') as $status => $total) {
            $stats[$status] = (int) $total;
        }

        $page = $base
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->with(['product', 'branch', 'creator', 'checker', 'poster', 'reverser'])
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (SavingsInterestRun $run) => $this->presenter->run($run))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'stats' => $stats,
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/savings/interest-runs/preview',
        summary: 'Preview a savings interest run',
        description: 'Writes nothing. One draft-to-be per branch holding an account on the product, with every line\'s days, average daily balance, rate, gross, tax and net, or why it earns nothing (`skip_reason`: below_minimum_adb, no_balance, no_days, closed or no_rate). The period starts on a crediting-period start and runs to its end (a calendar month, quarter, half or year).',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['product_id', 'period_start'], properties: [
            new OA\Property(property: 'product_id', type: 'integer'),
            new OA\Property(property: 'period_start', type: 'string', format: 'date', example: '2026-07-01'),
            new OA\Property(property: 'branch_ids', type: 'array', items: new OA\Items(type: 'integer')),
        ])),
        responses: [
            new OA\Response(response: 200, description: '{period_start, period_end, product, runs[{branch, account_count, total_gross, total_tax, total_net, lines}], totals}'),
            new OA\Response(response: 403, description: 'Missing savings:post'),
            new OA\Response(response: 422, description: 'A time deposit product, a period that hasn\'t ended, isn\'t a period start, or doesn\'t follow the last posted one, an earlier run not posted, or a branch that isn\'t the caller\'s'),
        ],
    )]
    public function preview(InterestRunRequest $request): JsonResponse
    {
        $product = SavingsProduct::query()->findOrFail($request->validated('product_id'));
        $preview = $this->builder->preview($product, $request->validated('period_start'), $this->branches->for($request->user()), $request->branchIds());

        return response()->json(['success' => true, 'data' => $this->presenter->preview($product, $preview)]);
    }

    #[OA\Post(
        path: '/api/savings/interest-runs',
        summary: 'Create savings interest runs',
        description: 'Saves one draft per branch, with the preview\'s figures, and answers the array of runs. A different user approves each before it can be posted.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['product_id', 'period_start'], properties: [
            new OA\Property(property: 'product_id', type: 'integer'),
            new OA\Property(property: 'period_start', type: 'string', format: 'date', example: '2026-07-01'),
            new OA\Property(property: 'branch_ids', type: 'array', items: new OA\Items(type: 'integer')),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The draft runs'),
            new OA\Response(response: 403, description: 'Missing savings:post'),
            new OA\Response(response: 409, description: 'A draft or approved run already holds the period'),
            new OA\Response(response: 422, description: 'As for the preview, or no account to credit'),
        ],
    )]
    public function store(InterestRunRequest $request): JsonResponse
    {
        $product = SavingsProduct::query()->findOrFail($request->validated('product_id'));
        $runs = $this->builder->create($product, $request->validated('period_start'), $this->branches->for($request->user()), $request->branchIds(), $request->user());

        return response()->json(['success' => true, 'data' => array_map(fn (SavingsInterestRun $run) => $this->presenter->run($run), $runs)], 201);
    }

    #[OA\Get(
        path: '/api/savings/interest-runs/{run}',
        summary: 'A savings interest run with its lines',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The run and its lines'),
            new OA\Response(response: 403, description: 'Missing savings:view'),
            new OA\Response(response: 404, description: 'No such run in the caller\'s branches'),
        ],
    )]
    public function show(Request $request, int $run): JsonResponse
    {
        $this->authorize('savings:view');

        return $this->answer($this->presenter->find($request->user(), $run));
    }

    #[OA\Post(
        path: '/api/savings/interest-runs/{run}/approve',
        summary: 'Approve a draft savings interest run',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The approved run'),
            new OA\Response(response: 403, description: 'Missing savings:approve, or the caller created the run'),
            new OA\Response(response: 404, description: 'No such run in the caller\'s branches'),
            new OA\Response(response: 409, description: 'The run is not a draft'),
        ],
    )]
    public function approve(Request $request, int $run): JsonResponse
    {
        $this->authorize('savings:approve');

        return $this->answer($this->poster->approve($this->presenter->find($request->user(), $run), $request->user()));
    }

    #[OA\Post(
        path: '/api/savings/interest-runs/{run}/reject',
        summary: 'Reject a draft or approved savings interest run',
        description: 'Frees the period, so it can be run again.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
            new OA\Property(property: 'reason', type: 'string', maxLength: 500),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The rejected run'),
            new OA\Response(response: 403, description: 'Missing savings:approve, or the caller created the run'),
            new OA\Response(response: 404, description: 'No such run in the caller\'s branches'),
            new OA\Response(response: 409, description: 'The run is posted, rejected or reversed'),
            new OA\Response(response: 422, description: 'No reason'),
        ],
    )]
    public function reject(DecideInterestRunRequest $request, int $run): JsonResponse
    {
        return $this->answer($this->poster->reject($this->presenter->find($request->user(), $run), $request->user(), $request->validated('reason')));
    }

    #[OA\Post(
        path: '/api/savings/interest-runs/{run}/post',
        summary: 'Post an approved savings interest run',
        description: 'Works the figures out again under the account locks and refuses (409) when any line changed since the draft. Credits each account\'s interest and withholding tax (value-dated the period\'s end, dated today) and posts one journal.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The posted run with its lines'),
            new OA\Response(response: 403, description: 'Missing savings:post'),
            new OA\Response(response: 404, description: 'No such run in the caller\'s branches'),
            new OA\Response(response: 409, description: 'Not approved, already posted, or the figures changed'),
            new OA\Response(response: 422, description: 'The books refused the journal, or the accounting period is closed'),
        ],
    )]
    public function post(Request $request, int $run): JsonResponse
    {
        $this->authorize('savings:post');

        return $this->answer($this->poster->post($this->presenter->find($request->user(), $run), $request->user()));
    }

    #[OA\Post(
        path: '/api/savings/interest-runs/{run}/reverse',
        summary: 'Reverse the latest posted savings interest run',
        description: 'Writes a reversal row for each interest and tax row, reverses the journal and frees the period, which is then run again as a new run.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
            new OA\Property(property: 'reason', type: 'string', maxLength: 500),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The reversed run'),
            new OA\Response(response: 403, description: 'Missing savings:approve'),
            new OA\Response(response: 404, description: 'No such run in the caller\'s branches'),
            new OA\Response(response: 409, description: 'The run is not posted'),
            new OA\Response(response: 422, description: 'Not the latest run, an account would go below ₱0.00 or is closed, or no reason'),
        ],
    )]
    public function reverse(DecideInterestRunRequest $request, int $run): JsonResponse
    {
        return $this->answer($this->reverser->reverse($this->presenter->find($request->user(), $run), $request->user(), $request->validated('reason')));
    }

    private function answer(SavingsInterestRun $run): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->presenter->run($run->fresh() ?? $run, true)]);
    }
}
