<?php

namespace App\Http\Controllers\Api\Savings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\AutoCredit\AutoCreditRunRequest;
use App\Http\Requests\Savings\AutoCredit\ListAutoCreditInstructionsRequest;
use App\Http\Requests\Savings\AutoCredit\StoreAutoCreditInstructionRequest;
use App\Http\Requests\Savings\AutoCredit\UpdateAutoCreditInstructionRequest;
use App\Services\Savings\AutoCredit\AutoCreditRunner;
use App\Services\Savings\AutoCredit\InstructionPresenter;
use App\Services\Savings\AutoCredit\Instructions;
use App\Services\Savings\IdempotentWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Savings auto-credit (gap round S3): members' standing instructions and the
 * run that credits them on the 15th and the 30th. `savings:view` reads
 * instructions; `savings:post` writes them and previews and processes runs.
 * Another branch's instruction answers 404; a run covers the caller's
 * branches only. Processing needs an `Idempotency-Key` header.
 */
class SavingsAutoCreditController extends Controller
{
    public function __construct(
        private readonly Instructions $instructions,
        private readonly InstructionPresenter $presenter,
    ) {}

    #[OA\Get(
        path: '/api/savings/auto-credit/instructions',
        summary: 'Savings auto-credit instructions',
        description: 'Newest first, on accounts in the caller\'s branches.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'account_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'borrower_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'schedule', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['15', '30', '15/30'])),
            new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'One page of instructions'),
            new OA\Response(response: 403, description: 'Missing savings:view'),
        ],
    )]
    public function index(ListAutoCreditInstructionsRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $page = $this->instructions->visible($request->user())
            ->when(isset($filters['account_id']), fn ($q) => $q->where('account_id', $filters['account_id']))
            ->when(isset($filters['borrower_id']), fn ($q) => $q->whereHas('account', fn ($a) => $a->where('borrower_id', $filters['borrower_id'])))
            ->when(isset($filters['schedule']), fn ($q) => $q->where('schedule', $filters['schedule']))
            ->when(isset($filters['active']), fn ($q) => $q->where('active', (bool) $filters['active']))
            ->orderByDesc('id')
            ->paginate(min(100, (int) ($filters['per_page'] ?? 15)));

        return response()->json([
            'data' => $this->presenter->many($page->getCollection()),
            'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    #[OA\Get(
        path: '/api/savings/auto-credit/instructions/{instruction}',
        summary: 'One savings auto-credit instruction',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'instruction', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The instruction'),
            new OA\Response(response: 403, description: 'Missing savings:view'),
            new OA\Response(response: 404, description: 'No such instruction in the caller\'s branches'),
        ],
    )]
    public function show(Request $request, int $instruction): JsonResponse
    {
        $this->authorize('savings:view');

        return response()->json(['success' => true, 'data' => $this->presenter->one($this->instructions->find($request->user(), $instruction))]);
    }

    #[OA\Post(
        path: '/api/savings/auto-credit/instructions',
        summary: 'Record a member\'s auto-credit instruction',
        description: 'Needs `savings:post`. One active instruction per account per slot (the 15th, the 30th; "both" takes the two). Audited.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['account_id', 'amount', 'schedule', 'authorised_on', 'authorised_by'], properties: [
            new OA\Property(property: 'account_id', type: 'integer'),
            new OA\Property(property: 'amount', type: 'string', example: '500.00'),
            new OA\Property(property: 'schedule', type: 'string', enum: ['15', '30', '15/30']),
            new OA\Property(property: 'active', type: 'boolean', default: true),
            new OA\Property(property: 'authorised_on', type: 'string', format: 'date'),
            new OA\Property(property: 'authorised_by', type: 'string', description: 'Who authorised it, in words'),
            new OA\Property(property: 'remarks', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The instruction'),
            new OA\Response(response: 403, description: 'Missing savings:post'),
            new OA\Response(response: 422, description: 'A field, an account outside the caller\'s branches or closed, or a slot already taken'),
        ],
    )]
    public function store(StoreAutoCreditInstructionRequest $request): JsonResponse
    {
        $instruction = $this->instructions->create($request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $this->presenter->one($instruction)], 201);
    }

    #[OA\Put(
        path: '/api/savings/auto-credit/instructions/{instruction}',
        summary: 'Change an auto-credit instruction',
        description: 'Needs `savings:post`. Any field but the account. Audited.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'instruction', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The instruction'),
            new OA\Response(response: 403, description: 'Missing savings:post'),
            new OA\Response(response: 404, description: 'No such instruction in the caller\'s branches'),
            new OA\Response(response: 422, description: 'A field, the account sent, or a slot already taken'),
        ],
    )]
    public function update(UpdateAutoCreditInstructionRequest $request): JsonResponse
    {
        $instruction = $this->instructions->update($request->instruction(), $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => $this->presenter->one($instruction->refresh())]);
    }

    #[OA\Delete(
        path: '/api/savings/auto-credit/instructions/{instruction}',
        summary: 'Delete an auto-credit instruction that never credited',
        description: 'Needs `savings:post`. One that has credited the account is kept for the record (422): deactivate it instead. Audited.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'instruction', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 403, description: 'Missing savings:post'),
            new OA\Response(response: 404, description: 'No such instruction in the caller\'s branches'),
            new OA\Response(response: 422, description: 'It has already credited the account'),
        ],
    )]
    public function destroy(UpdateAutoCreditInstructionRequest $request): JsonResponse
    {
        $this->instructions->delete($request->instruction());

        return response()->json(['success' => true, 'message' => 'Auto-credit instruction deleted.']);
    }

    #[OA\Get(
        path: '/api/savings/auto-credit/preview',
        summary: 'Preview a savings auto-credit run',
        description: 'Needs `savings:post`. The same plan a run would post now, written nowhere: `credits`, `skipped` (with a reason in words), `already_credited` and `totals`, all server figures.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'cycle_date', in: 'query', required: true, description: 'A 15th or a month\'s last day, today or up to 92 days ago', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'method', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: AutoCreditRunner::METHODS, default: 'bank_transfer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The plan'),
            new OA\Response(response: 403, description: 'Missing savings:post'),
            new OA\Response(response: 422, description: 'Not a cycle date, in the future or too long ago'),
        ],
    )]
    public function preview(AutoCreditRunRequest $request, AutoCreditRunner $runner): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['success' => true, 'data' => $runner->preview($data['cycle_date'], $data['method'], $data['reference_number'] ?? null, $request->user())]);
    }

    #[OA\Post(
        path: '/api/savings/auto-credit/process',
        summary: 'Process a savings auto-credit run',
        description: 'Needs `savings:post` and an `Idempotency-Key` header. Posts one deposit per due instruction (Dr the method\'s account, the bank by default / Cr 2050), dated today, recorded per instruction and cycle, so a re-run of the cycle posts nothing. Skips, in words, former members, frozen and closed accounts and amounts the product\'s rules refuse.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['cycle_date'], properties: [
            new OA\Property(property: 'cycle_date', type: 'string', format: 'date'),
            new OA\Property(property: 'method', type: 'string', enum: AutoCreditRunner::METHODS, default: 'bank_transfer'),
            new OA\Property(property: 'reference_number', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The run: `run_id`, `credits`, `skipped`, `already_credited`, `totals`'),
            new OA\Response(response: 403, description: 'Missing savings:post'),
            new OA\Response(response: 409, description: 'Another write got there first'),
            new OA\Response(response: 422, description: 'Not a cycle date, in the future or too long ago, or a closed period'),
        ],
    )]
    public function process(AutoCreditRunRequest $request, AutoCreditRunner $runner, IdempotentWrite $idempotent): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        return $idempotent->run($request, fn (): JsonResponse => response()->json([
            'success' => true,
            'data' => $runner->process($data['cycle_date'], $data['method'], $data['reference_number'] ?? null, $user),
        ], 201));
    }
}
