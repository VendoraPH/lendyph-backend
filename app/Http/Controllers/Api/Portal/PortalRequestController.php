<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\PortalRequest;
use App\Models\SavingsAccount;
use App\Services\GeneratedNumberWrite;
use App\Services\Portal\MemberScope;
use App\Services\Portal\PortalSettings;
use App\Services\Portal\Requests\OpeningRequests;
use App\Services\Portal\Requests\ProfileFields;
use App\Services\Portal\Requests\RequestDesk;
use App\Services\Portal\Requests\RequestPresenter;
use App\Services\Portal\Requests\WithdrawalRequests;
use App\Services\Savings\AvailableBalance;
use App\Services\Savings\IdempotentWrite;
use App\Services\Savings\SavingsMath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * The member's requests center (portal design §3, §6): profile changes,
 * savings withdrawals and new savings accounts. The member comes only from
 * the token; a request is found within the member's own, so another member's
 * id answers 404 (looked up before the body is read).
 */
class PortalRequestController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly MemberScope $scope,
        private readonly RequestDesk $desk,
        private readonly RequestPresenter $presenter,
    ) {}

    #[OA\Get(
        path: '/api/portal/requests',
        summary: 'The member\'s requests',
        description: 'Signed-in portal token. Newest first, 20 a page. Rows: {id, reference, type, status, summary, amount, expires_on, created_at, updated_at, can_cancel, can_reply}; `meta` {current_page, last_page, per_page, total}.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: PortalRequest::STATUSES)),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: PortalRequest::TYPES)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'The requests'), new OA\Response(response: 422, description: 'An unknown status or type')],
    )]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(PortalRequest::STATUSES)],
            'type' => ['nullable', 'string', Rule::in(PortalRequest::TYPES)],
        ]);

        $page = PortalRequest::query()
            ->where('borrower_id', $this->scope->borrowerId())
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (PortalRequest $r) => $this->presenter->memberRow($r))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    #[OA\Get(
        path: '/api/portal/requests/options',
        summary: 'What the member can ask for',
        description: 'Signed-in portal token. {types{profile_update, savings_withdrawal, savings_opening} (each switch), withdrawal_accounts[{id, account_number, product_name, withdrawable, has_open_request}] (`withdrawable` is the server\'s available balance: holds, pending requests and the maintaining balance already taken off), payout_methods[], hold_days, opening_products[{id, name, minimum_opening_balance}], profile_fields[{field, label, current}] (masked as the profile is)}.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The options')],
    )]
    public function options(PortalSettings $settings, AvailableBalance $available, OpeningRequests $openings, ProfileFields $fields): JsonResponse
    {
        $portalUser = $this->scope->portalUser();
        $member = $portalUser->borrower;
        $types = [];

        foreach (PortalRequest::TYPES as $type) {
            $types[$type] = $this->desk->typeOn($type);
        }

        $accounts = $types[PortalRequest::TYPE_SAVINGS_WITHDRAWAL]
            ? SavingsAccount::query()->with('product:id,name,portal_withdrawal_requests')
                ->where('borrower_id', $member->id)
                ->where('status', SavingsAccount::STATUS_ACTIVE)
                ->orderBy('id')
                ->get()
                ->filter(fn (SavingsAccount $a) => (bool) $a->product?->portal_withdrawal_requests)
                ->values()
            : collect();
        $breakdowns = $available->forMany($accounts);
        $openKeys = PortalRequest::query()->whereIn('live_key', $accounts->map(fn (SavingsAccount $a) => 'w:'.$a->id))->pluck('live_key')->all();

        $products = $types[PortalRequest::TYPE_SAVINGS_OPENING]
            ? $openings->openProducts()
                ->whereNotIn('id', SavingsAccount::query()->select('product_id')->where('borrower_id', $member->id)->whereNotNull('open_key'))
                ->orderBy('name')
                ->get(['id', 'name', 'minimum_opening_balance'])
            : collect();

        $setting = $settings->current();

        return response()->json(['success' => true, 'data' => [
            'types' => $types,
            'withdrawal_accounts' => $accounts->map(fn (SavingsAccount $a): array => [
                'id' => $a->id,
                'account_number' => $a->account_number,
                'product_name' => $a->product?->name,
                'withdrawable' => SavingsMath::pesos($breakdowns[$a->id]['available'] ?? 0),
                'has_open_request' => in_array('w:'.$a->id, $openKeys, true),
            ])->all(),
            'payout_methods' => array_values(array_intersect(array_keys(WithdrawalRequests::METHODS), $setting->withdrawal_methods ?? [])),
            'hold_days' => (int) ($setting->withdrawal_hold_days ?? 7),
            'opening_products' => $products->map(fn ($p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'minimum_opening_balance' => (string) $p->minimum_opening_balance,
            ])->all(),
            'profile_fields' => $types[PortalRequest::TYPE_PROFILE_UPDATE]
                ? collect(ProfileFields::labels())->map(fn (string $label, string $field): array => [
                    'field' => $field,
                    'label' => $label,
                    'current' => $fields->shown($field, $member->{$field}),
                ])->values()->all()
                : [],
        ]]);
    }

    #[OA\Get(
        path: '/api/portal/requests/{id}',
        summary: 'One of the member\'s requests',
        description: 'Signed-in portal token. The row plus `details` (by type; payout numbers masked to the last 4, profile values masked) and `timeline[{from_status, to_status, actor (member|staff|system), message, created_at}]`. 404 for another member\'s request.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The request'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function show(int $id): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->presenter->member($this->find($id))]);
    }

    #[OA\Post(
        path: '/api/portal/requests',
        summary: 'Send a request',
        description: 'Signed-in portal token and an `Idempotency-Key` header (UUID): a replay answers the stored 201 (`Idempotent-Replayed: true`), the same key with another body 422. '
            .'`profile_update` details: any of street_address, barangay, city, province, contact_number, email, civil_status, employer_or_business (the member update rules). '
            .'`savings_withdrawal` details: account_id, amount (≤ withdrawable), method (cash_pickup|bank_transfer|gcash) plus bank_name, account_name, account_number or gcash_number; it reserves the amount with a hold at once. '
            .'`savings_opening` details: product_id, initial_deposit (≥ the product\'s minimum), terms_accepted. 403 when that type is switched off; 409 for a second open request of the same kind.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['type', 'details'], properties: [
            new OA\Property(property: 'type', type: 'string', enum: PortalRequest::TYPES),
            new OA\Property(property: 'details', type: 'object'),
            new OA\Property(property: 'remarks', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The request'),
            new OA\Response(response: 403, description: 'That type is switched off'),
            new OA\Response(response: 409, description: 'An open request of the same kind, or another write got there first'),
            new OA\Response(response: 422, description: 'Invalid details, above withdrawable, or no Idempotency-Key'),
        ],
    )]
    public function store(Request $request, IdempotentWrite $idempotent): JsonResponse
    {
        $portalUser = $this->scope->portalUser();

        // A retry of a request already sent gets its stored answer, even when
        // today's checks (a profile change since applied) would now refuse it.
        $replayed = $idempotent->replayed($request, IdempotentWrite::STORE_PORTAL);

        if ($replayed !== null) {
            return $replayed;
        }

        $validated = $this->desk->validate($portalUser, $request->all());

        return $idempotent->run($request, function () use ($portalUser, $validated): JsonResponse {
            $created = $this->desk->submit($portalUser, $validated);

            return response()->json(['success' => true, 'data' => $this->presenter->member($created->refresh())], 201);
        }, GeneratedNumberWrite::PORTAL_REQUEST_CONFLICT, IdempotentWrite::STORE_PORTAL);
    }

    #[OA\Post(
        path: '/api/portal/requests/{id}/cancel',
        summary: 'Cancel a request',
        description: 'Signed-in portal token. While submitted, under review or needing information; a withdrawal\'s hold is released. 409 otherwise; 404 for another member\'s request.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The request'), new OA\Response(response: 404, description: 'Not found'), new OA\Response(response: 409, description: 'Already decided')],
    )]
    public function cancel(int $id): JsonResponse
    {
        $found = $this->find($id);
        $cancelled = GeneratedNumberWrite::run(fn (): PortalRequest => DB::transaction(fn (): PortalRequest => $this->desk->cancel($found)), GeneratedNumberWrite::PORTAL_REQUEST_CONFLICT);

        return response()->json(['success' => true, 'data' => $this->presenter->member($cancelled->refresh())]);
    }

    #[OA\Post(
        path: '/api/portal/requests/{id}/reply',
        summary: 'Reply to the branch',
        description: 'Signed-in portal token. Only while the branch asked for more information; the request goes back to review. 409 otherwise; 404 for another member\'s request.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['message'], properties: [new OA\Property(property: 'message', type: 'string', maxLength: 1000)])),
        responses: [new OA\Response(response: 200, description: 'The request'), new OA\Response(response: 404, description: 'Not found'), new OA\Response(response: 409, description: 'Not waiting for a reply'), new OA\Response(response: 422, description: 'No message')],
    )]
    public function reply(Request $request, int $id): JsonResponse
    {
        $found = $this->find($id);
        $message = $request->validate(['message' => ['required', 'string', 'max:1000']], [
            'message.required' => 'Write your reply.',
            'message.max' => 'The reply can be at most 1,000 characters.',
        ])['message'];

        $replied = GeneratedNumberWrite::run(fn (): PortalRequest => DB::transaction(fn (): PortalRequest => $this->desk->reply($found, $message)), GeneratedNumberWrite::PORTAL_REQUEST_CONFLICT);

        return response()->json(['success' => true, 'data' => $this->presenter->member($replied->refresh())]);
    }

    private function find(int $id): PortalRequest
    {
        /** @var PortalRequest */
        return $this->scope->findOrFail(PortalRequest::query(), $id);
    }
}
