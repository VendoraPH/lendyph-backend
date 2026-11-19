<?php

namespace App\Http\Controllers\Api\Amla;

use App\Http\Controllers\Controller;
use App\Http\Requests\Amla\ReleaseAmlaOrderRequest;
use App\Http\Requests\Amla\StoreAmlaOrderRequest;
use App\Http\Resources\Amla\AmlaScreeningPresenter;
use App\Models\AmlaOrder;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\Amla\Screening\Orders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * The freeze-order register (AMLA-B5; AMLA design §3.5, decision 5). Every
 * endpoint needs `amla:sanctions` and head-office access, checked before any
 * lookup. An order's holds are legal savings holds with no end date that
 * only its release lifts. Every list and view is written to the AMLA log.
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
        description: 'Needs `amla:sanctions` and `branches:view_all`. `reference`, `authority` (amlc, court, other), `received_on`, `borrower_id`, `required_action`, optional `details`, `responsible_officer_id` and `return_due_on`, and `holds` (each an `account_id` of the member\'s own and an `amount`, up to the balance not already held). Each hold is a legal savings hold with no end date, reading only "Held under a legal order."; only this order\'s release lifts it. One transaction: a hold that doesn\'t fit refuses the order.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'The order'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words'), new OA\Response(response: 422, description: 'Invalid, or a hold that doesn\'t fit, in words')],
    )]
    public function store(StoreAmlaOrderRequest $request, Orders $orders): JsonResponse
    {
        $order = $orders->place($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'The order is recorded and its holds are placed.',
            'data' => AmlaScreeningPresenter::order($order->fresh(['borrower', 'placer', 'responsibleOfficer', 'releaser']), true),
        ], 201);
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
        summary: 'Release an order and lift its holds',
        description: 'Needs `amla:sanctions` and `branches:view_all`. `reason` and `release_reference` required. Lifts every hold the order placed (each reads "Released when the legal order was lifted."), once: a released order answers 409.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Released'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words'), new OA\Response(response: 409, description: 'Already released')],
    )]
    public function release(ReleaseAmlaOrderRequest $request, Orders $orders, int $id): JsonResponse
    {
        $order = $orders->release($request->user(), $id, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'The order is released and its holds are lifted.',
            'data' => AmlaScreeningPresenter::order($order->fresh(['borrower', 'placer', 'responsibleOfficer', 'releaser']), true),
        ]);
    }
}
