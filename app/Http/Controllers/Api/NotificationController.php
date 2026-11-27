<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * The signed-in user's own in-app notifications (decision N, Arvin,
 * 2026-11-02). Every query goes through `$request->user()->notifications()`,
 * so nobody can read or mark another user's rows: another user's id is a 404,
 * the same answer as an id that doesn't exist. No permission is needed; every
 * staff member has a bell.
 */
class NotificationController extends Controller
{
    #[OA\Get(
        path: '/api/notifications',
        summary: 'List the signed-in user\'s notifications, newest first',
        tags: ['Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'unread', in: 'query', required: false, description: 'Only unread notifications when true', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated notifications: id, type, title, body, url, read_at, created_at, data'),
            new OA\Response(response: 422, description: 'A non-boolean `unread` or a `per_page` below 1'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'unread' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = min((int) ($validated['per_page'] ?? 20), 100);

        $notifications = $request->user()
            ->notifications()
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            // Tiebreak on the key so rows sharing a `created_at` keep one
            // order across pages. See DeterministicPaginationTest.
            ->orderByDesc('id')
            ->paginate($perPage);

        return NotificationResource::collection($notifications);
    }

    #[OA\Get(
        path: '/api/notifications/unread-count',
        summary: 'How many of the signed-in user\'s notifications are unread',
        tags: ['Notifications'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The unread count', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'count', type: 'integer', example: 3),
                ]),
            ])),
        ],
    )]
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['count' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    #[OA\Post(
        path: '/api/notifications/{id}/read',
        summary: 'Mark one of the signed-in user\'s notifications read',
        description: 'Idempotent: a notification already read keeps its first read time. Another user\'s notification answers 404.',
        tags: ['Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The notification, now read'),
            new OA\Response(response: 404, description: 'No such notification for this user'),
        ],
    )]
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();

        $notification->markAsRead();

        // Wrapped by hand: the item has its own `data` key, which stops
        // JsonResource from adding the `data` wrapper a single resource gets.
        return response()->json(['data' => (new NotificationResource($notification))->resolve($request)]);
    }

    #[OA\Post(
        path: '/api/notifications/read-all',
        summary: 'Mark every unread notification of the signed-in user read',
        tags: ['Notifications'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'How many were marked read', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'marked_read', type: 'integer', example: 3),
                ]),
            ])),
        ],
    )]
    public function markAllRead(Request $request): JsonResponse
    {
        $marked = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read.',
            'data' => ['marked_read' => $marked],
        ]);
    }
}
