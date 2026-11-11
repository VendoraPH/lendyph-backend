<?php

namespace App\Http\Controllers\Api\PortalAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\Account\OpenSavingsAccountRequest;
use App\Models\PortalRequest;
use App\Services\BranchAccess;
use App\Services\GeneratedNumberWrite;
use App\Services\Portal\Requests\RequestPresenter;
use App\Services\Portal\Requests\StaffQueue;
use App\Services\Savings\IdempotentWrite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * The staff queue of member portal requests (portal design §3, §4, §6):
 * `member_portal:requests`, branch-scoped (another branch's request answers
 * 404 on show and decision; a `branch_id` filter outside the caller's
 * branches 403). A decision also needs its type's permission:
 * `borrowers:update` (profile), `savings:transact` (withdrawal; the savings
 * tiers apply) or `savings:create` (new account). The order is: the queue
 * permission (403), the request (404), the type permission (403), then the
 * body (422).
 */
class PortalRequestQueueController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly StaffQueue $queue,
        private readonly RequestPresenter $presenter,
    ) {}

    #[OA\Get(
        path: '/api/portal-admin/requests',
        summary: 'Member portal requests',
        description: 'member_portal:requests. The caller\'s branches, newest first, 25 a page. Rows: {id, reference, type, status, summary, amount, member{id, name, member_number}, branch{id, name}, expires_on, decided_by, decided_at, created_at, updated_at}. `meta.counts` per status (the other filters applied). A branch_id outside the caller\'s branches is 403.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: PortalRequest::TYPES)),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: PortalRequest::STATUSES)),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'The requests'), new OA\Response(response: 403, description: 'Missing member_portal:requests, or another branch')],
    )]
    public function index(Request $request, BranchAccess $branches): JsonResponse
    {
        $this->authorize('member_portal:requests');
        $branches->authorizeBranchFilter($request->user(), $request->query('branch_id'));

        $filters = $request->validate([
            'type' => ['nullable', 'string', Rule::in(PortalRequest::TYPES)],
            'status' => ['nullable', 'string', Rule::in(PortalRequest::STATUSES)],
            'branch_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $base = $this->queue->scope($request->user())
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('type', $type))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $branch) => $q->where('branch_id', (int) $branch))
            ->when($filters['search'] ?? null, function (Builder $q, string $search): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)).'%';
                $q->where(fn (Builder $w) => $w->where('reference', 'like', $like)
                    ->orWhereHas('borrower', fn (Builder $m) => $m->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                        ->orWhere('borrower_code', 'like', $like)->orWhere('membership_number', 'like', $like)));
            });

        $counts = (clone $base)->toBase()->select('status', DB::raw('COUNT(*) AS total'))->groupBy('status')->pluck('total', 'status');

        $page = (clone $base)
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->with(['borrower', 'branch:id,name'])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (PortalRequest $r) => $this->presenter->staffRow($r))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'counts' => collect(PortalRequest::STATUSES)->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)])->all(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/portal-admin/requests/{request}',
        summary: 'One member portal request',
        description: 'member_portal:requests. The row plus details (profile values in full with borrowers:view, masked otherwise; payout numbers in full with savings:view, last 4 otherwise), hold, approval, transaction, opened_account and the timeline with staff names. Another branch\'s request is 404.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'request', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The request'), new OA\Response(response: 403, description: 'Missing member_portal:requests'), new OA\Response(response: 404, description: 'Not in the caller\'s branches')],
    )]
    public function show(Request $http, int $request): JsonResponse
    {
        $this->authorize('member_portal:requests');

        return response()->json(['success' => true, 'data' => $this->presenter->staff($this->queue->find($http->user(), $request), $http->user())]);
    }

    #[OA\Post(
        path: '/api/portal-admin/requests/{request}/decision',
        summary: 'Decide a member portal request',
        description: 'member_portal:requests plus the type\'s permission. `decision`: under_review, need_info (message required), reject (message required; a withdrawal\'s hold is released) or approve. '
            .'Approve needs an `Idempotency-Key` header: a withdrawal (payout{date, method, reference}) releases its hold and posts through the savings teller: 201 completed up to tier 1, 202 approved above it (the savings approvals queue decides; maker ≠ checker); a new account (deposit{date, method, reference}) opens it with the deposit received: 201; a profile change writes the member: 200. A stale status is 409.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'request', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['decision'], properties: [
            new OA\Property(property: 'decision', type: 'string', enum: StaffQueue::DECISIONS),
            new OA\Property(property: 'message', type: 'string', nullable: true),
            new OA\Property(property: 'payout', type: 'object', nullable: true),
            new OA\Property(property: 'deposit', type: 'object', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The request'),
            new OA\Response(response: 201, description: 'Approved and posted, or the account opened'),
            new OA\Response(response: 202, description: 'Approved, waiting for the second approval'),
            new OA\Response(response: 403, description: 'Missing the queue or the type permission, or the maker checking their own'),
            new OA\Response(response: 404, description: 'Not in the caller\'s branches'),
            new OA\Response(response: 409, description: 'Already decided, or another write got there first'),
            new OA\Response(response: 422, description: 'Invalid body, refused by the savings rules, or no Idempotency-Key'),
        ],
    )]
    public function decide(Request $http, IdempotentWrite $idempotent, int $request): JsonResponse
    {
        $this->authorize('member_portal:requests');
        $staff = $http->user();
        $found = $this->queue->find($staff, $request);
        $this->authorize(StaffQueue::TYPE_PERMISSIONS[$found->type] ?? 'member_portal:settings');

        $input = $http->validate($this->rules($found, (string) $http->input('decision')), $this->messages());

        if ($input['decision'] !== 'approve') {
            $decided = GeneratedNumberWrite::run(fn (): PortalRequest => DB::transaction(
                fn (): PortalRequest => $this->queue->decide($found, $input['decision'], $input['message'] ?? null, $staff),
            ), GeneratedNumberWrite::PORTAL_REQUEST_CONFLICT);

            return response()->json(['success' => true, 'data' => $this->presenter->staff($decided->refresh(), $staff)]);
        }

        return $idempotent->run($http, function () use ($found, $input, $staff): JsonResponse {
            $result = $this->queue->approve($found, $input, $staff);

            return response()->json(['success' => true, 'data' => $this->presenter->staff($result['request']->refresh(), $staff)], $result['status']);
        });
    }

    /** @return array<string, mixed> */
    private function rules(PortalRequest $request, string $decision): array
    {
        $approving = $decision === 'approve';
        $businessDate = ['date_format:Y-m-d', 'before_or_equal:'.now()->toDateString(), 'after_or_equal:'.now()->subDay()->toDateString()];
        $rules = [
            'decision' => ['required', 'string', Rule::in(StaffQueue::DECISIONS)],
            'message' => [Rule::requiredIf(in_array($decision, ['need_info', 'reject'], true)), 'nullable', 'string', 'max:1000'],
        ];

        if ($approving && $request->type === PortalRequest::TYPE_SAVINGS_WITHDRAWAL) {
            $rules += [
                'payout' => ['required', 'array'],
                'payout.date' => ['required', ...$businessDate],
                'payout.method' => ['required', 'string', Rule::in(OpenSavingsAccountRequest::PAYMENT_METHODS)],
                'payout.reference' => ['nullable', 'string', 'max:64'],
            ];
        }

        if ($approving && $request->type === PortalRequest::TYPE_SAVINGS_OPENING) {
            $rules += [
                'deposit' => ['required', 'array'],
                'deposit.date' => ['required', ...$businessDate],
                'deposit.method' => [Rule::requiredIf((float) $request->amount > 0), 'nullable', 'string', Rule::in(OpenSavingsAccountRequest::PAYMENT_METHODS)],
                'deposit.reference' => ['nullable', 'string', 'max:64'],
            ];
        }

        return $rules;
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'decision.required' => 'Choose under review, need more information, approve or reject.',
            'decision.in' => 'Choose under review, need more information, approve or reject.',
            'message.required' => 'Write a message to the member: it is shown on their request.',
            'message.max' => 'The message can be at most 1,000 characters.',
            'payout.required' => 'Enter how the money was paid out: the date, method and reference.',
            'payout.date.required' => 'Enter the payout date.',
            'payout.date.before_or_equal' => 'The payout date can\'t be after today.',
            'payout.date.after_or_equal' => 'The payout date can be today or yesterday only.',
            'payout.method.required' => 'Choose how the money was paid out: cash, bank transfer, GCash or Maya.',
            'payout.method.in' => 'Choose how the money was paid out: cash, bank transfer, GCash or Maya.',
            'deposit.required' => 'Enter the opening deposit received: the date, method and reference.',
            'deposit.date.required' => 'Enter the date the deposit was received.',
            'deposit.date.before_or_equal' => 'The deposit date can\'t be after today.',
            'deposit.date.after_or_equal' => 'The deposit date can be today or yesterday only.',
            'deposit.method.required' => 'Choose how the opening deposit was paid.',
            'deposit.method.in' => 'Choose how the opening deposit was paid: cash, bank transfer, GCash or Maya.',
        ];
    }
}
