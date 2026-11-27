<?php

namespace App\Http\Controllers\Api\Amla;

use App\Http\Controllers\Controller;
use App\Http\Requests\Amla\DecideAmlaOrderReleaseRequest;
use App\Http\Requests\Amla\RejectAmlaOrderReleaseRequest;
use App\Http\Requests\Amla\ReleaseAmlaOrderRequest;
use App\Http\Requests\Amla\StoreAmlaOrderRequest;
use App\Http\Resources\Amla\AmlaScreeningPresenter;
use App\Models\AmlaOrder;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\Amla\Screening\Orders;
use App\Services\Savings\IdempotentWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * The freeze-order register (AMLA-B5; AMLA design §3.5, decision 5). Every
 * endpoint needs `amla:sanctions` and head-office access, checked before any
 * lookup. An order's holds are legal savings holds with no end date that
 * only its release lifts, and a release takes two people: one asks, another
 * approves. Every list and view is written to the AMLA log.
 */
class AmlaOrderController extends Controller
{
    public function __construct(private readonly AmlaAccess $access, private readonly AmlaLog $log) {}

    #[OA\Get(
        path: '/api/amla/orders',
        summary: 'Orders',
        description: 'Needs `amla:sanctions` and `branches:view_all`. Newest first, 25 a page; `status` (active, released). Logged to the AMLA log.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The orders'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->access->authorizeHeadOffice($request->user(), AmlaAccess::SANCTIONS);

        $filters = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(array_keys(AmlaOrder::STATUSES))],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $this->log->record('orders_listed', $request->user(), details: array_intersect_key($filters, array_flip(['status', 'page'])));

        $page = AmlaOrder::query()
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->with('borrower', 'placer')
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (AmlaOrder $order) => AmlaScreeningPresenter::order($order, false))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    #[OA\Post(
        path: '/api/amla/orders',
        summary: 'Record an order and place its holds',
        description: 'Needs `amla:sanctions` and `branches:view_all`, and an `Idempotency-Key` header (UUID): a replay answers the first reply. `reference`, `authority` (amlc, court, other), `received_on`, `borrower_id`, `required_action`, optional `details`, `responsible_officer_id` and `return_due_on`, and `holds` (may be empty; each an `account_id` of the member\'s own and an `amount`, up to the balance not already held by another legal order: order holds stack on other holds). While the order is in force, the member\'s time deposits can\'t be ended early or paid out of the cooperative. Each hold is a legal savings hold with no end date, reading only "Held under a legal order."; only this order\'s release lifts it. One transaction: a hold that doesn\'t fit refuses the order.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'The order'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words'), new OA\Response(response: 422, description: 'Invalid, or a hold that doesn\'t fit, in words')],
    )]
    public function store(StoreAmlaOrderRequest $request, Orders $orders, IdempotentWrite $idempotent): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        /*
         * The stored reply (savings_idempotency_keys, for a replay) holds the
         * order's id and status only, never its details, which stay encrypted
         * on the AMLA side; GET /amla/orders/{id} reads them, logged.
         */
        return $idempotent->run($request, function () use ($orders, $user, $data): JsonResponse {
            $order = $orders->place($user, $data);

            return response()->json([
                'success' => true,
                'message' => 'The order is recorded and its holds are placed.',
                'data' => [
                    'id' => (int) $order->id,
                    'status' => $order->status,
                    'holds' => $order->holds()->orderBy('id')->get(['savings_hold_id', 'account_id', 'amount'])
                        ->map(fn ($link) => ['savings_hold_id' => (int) $link->savings_hold_id, 'account_id' => (int) $link->account_id, 'amount' => (string) $link->amount])->values()->all(),
                ],
            ], 201);
        });
    }

    #[OA\Get(
        path: '/api/amla/orders/{id}',
        summary: 'One order',
        description: 'Needs `amla:sanctions` and `branches:view_all`, checked before the lookup. Logged to the AMLA log.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The order'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function show(Request $request, int $id): JsonResponse
    {
        $this->access->authorizeHeadOffice($request->user(), AmlaAccess::SANCTIONS);
        $order = AmlaOrder::query()->with('borrower', 'placer', 'responsibleOfficer', 'releaser')->findOrFail($id);

        $this->log->record('order_viewed', $request->user(), 'order', $order->id, ['order_id' => $order->id]);

        return response()->json(['success' => true, 'data' => AmlaScreeningPresenter::order($order, true)]);
    }

    #[OA\Post(
        path: '/api/amla/orders/{id}/release',
        summary: 'Ask for an order\'s release',
        description: 'Needs `amla:sanctions` and `branches:view_all`. `reason` and `release_reference` (the lifting order or resolution). Nothing is lifted yet: a different `amla:sanctions` holder approves it. One waiting request per order (422); a released order answers 409.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 202, description: 'Waiting for approval'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words'), new OA\Response(response: 409, description: 'Already released'), new OA\Response(response: 422, description: 'Invalid, or one already waiting')],
    )]
    public function release(ReleaseAmlaOrderRequest $request, Orders $orders, int $id): JsonResponse
    {
        $orders->requestRelease($request->user(), $id, $request->validated());

        return $this->respond($id, 'The release is waiting for another AMLA sanctions holder to approve it.', 202);
    }

    #[OA\Post(
        path: '/api/amla/orders/{id}/release/approve',
        summary: 'Approve an order\'s release and lift its holds',
        description: 'Needs `amla:sanctions` and `branches:view_all`, and not the person who asked (403, super_admin included). Optional `note`. Lifts every hold the order placed (each reads "Released when the legal order was lifted.").',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Released'), new OA\Response(response: 403, description: 'Missing permission, head-office access, or the asker'), new OA\Response(response: 409, description: 'Nothing waiting, or already released')],
    )]
    public function approveRelease(DecideAmlaOrderReleaseRequest $request, Orders $orders, int $id): JsonResponse
    {
        $orders->approveRelease($request->user(), $id, $request->validated('note'));

        return $this->respond($id, 'The order is released and its holds are lifted.');
    }

    #[OA\Post(
        path: '/api/amla/orders/{id}/release/reject',
        summary: 'Reject an order\'s release',
        description: 'Needs `amla:sanctions` and `branches:view_all`, and not the person who asked (403: they withdraw). `note` required. The order stays in force.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Rejected'), new OA\Response(response: 403, description: 'Missing permission, head-office access, or the asker'), new OA\Response(response: 409, description: 'Nothing waiting')],
    )]
    public function rejectRelease(RejectAmlaOrderReleaseRequest $request, Orders $orders, int $id): JsonResponse
    {
        $orders->rejectRelease($request->user(), $id, (string) $request->validated('note'));

        return $this->respond($id, 'The release is rejected. The order stays in force.');
    }

    #[OA\Post(
        path: '/api/amla/orders/{id}/release/withdraw',
        summary: 'Withdraw your own release request',
        description: 'Needs `amla:sanctions` and `branches:view_all`; only the person who asked (403). The order stays in force.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Withdrawn'), new OA\Response(response: 403, description: 'Missing permission, head-office access, or not the asker'), new OA\Response(response: 409, description: 'Nothing waiting')],
    )]
    public function withdrawRelease(DecideAmlaOrderReleaseRequest $request, Orders $orders, int $id): JsonResponse
    {
        $orders->withdrawRelease($request->user(), $id);

        return $this->respond($id, 'The release request is withdrawn. The order stays in force.');
    }

    private function respond(int $id, string $message, int $status = 200): JsonResponse
    {
        $order = AmlaOrder::query()->with('borrower', 'placer', 'responsibleOfficer', 'releaser', 'releaseRequester')->findOrFail($id);

        return response()->json(['success' => true, 'message' => $message, 'data' => AmlaScreeningPresenter::order($order, true)], $status);
    }
}
