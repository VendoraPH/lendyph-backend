<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\MemberScope;
use App\Services\Portal\Read\NotificationReader;
use App\Services\Portal\Read\PortalPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use OpenApi\Attributes as OA;

/**
 * The member's in-app notices (portal design §1, §7). Their own only:
 * another member's notice answers 404.
 */
class PortalNotificationController extends Controller
{
    public function __construct(
        private readonly MemberScope $scope,
        private readonly NotificationReader $notifications,
    ) {}

    #[OA\Get(
        path: '/api/portal/notifications',
        summary: 'The member\'s notices',
        description: 'Signed-in portal token. Newest first, paged (per_page ≤ 50): [{id, category, title, body, created_at, read_at, link (a /member/... path, or null)}], filtered by `category` and `unread`.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'category', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'unread', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 50)),
        ],
        responses: [new OA\Response(response: 200, description: 'The notices')],
    )]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category' => ['nullable', 'string', 'alpha_dash', 'max:40'],
            'unread' => ['nullable', 'boolean'],
            'per_page' => PortalPage::PER_PAGE_RULES,
        ]);

        $page = $this->scope->portalUser()->notifications()
            ->when(isset($filters['category']), fn ($q) => $q->where('type', 'portal_'.$filters['category']))
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(PortalPage::perPage($filters['per_page'] ?? null));

        return PortalPage::json($page, collect($page->items())->map(fn (DatabaseNotification $n) => $this->notifications->present($n))->values()->all());
    }

    #[OA\Get(
        path: '/api/portal/notifications/unread-count',
        summary: 'The bell\'s unread count',
        description: 'Signed-in portal token. {notifications, announcements (visible and not yet opened), total}.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The counts')],
    )]
    public function unreadCount(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->notifications->unreadCount($this->scope->portalUser())]);
    }

    #[OA\Post(
        path: '/api/portal/notifications/{id}/read',
        summary: 'Mark one notice read',
        description: 'Signed-in portal token. Answers the notice. 404 for another member\'s notice.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'The notice'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function read(string $id): JsonResponse
    {
        $notice = $this->scope->portalUser()->notifications()->whereKey($id)->firstOrFail();
        $notice->markAsRead();

        return response()->json(['success' => true, 'data' => $this->notifications->present($notice)]);
    }

    #[OA\Post(
        path: '/api/portal/notifications/read-all',
        summary: 'Mark every notice read',
        description: 'Signed-in portal token. {marked_read}.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'How many were marked')],
    )]
    public function readAll(): JsonResponse
    {
        $marked = $this->scope->portalUser()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['success' => true, 'data' => ['marked_read' => $marked]]);
    }
}
