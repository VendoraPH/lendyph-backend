<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\PortalAnnouncement;
use App\Services\Portal\Announcements\AnnouncementFeed;
use App\Services\Portal\MemberScope;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The member's announcements (portal design §3, §7): published, started,
 * unexpired, and to an audience that includes the member. One outside the
 * member's audience answers 404, like a missing one.
 */
class PortalAnnouncementController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly MemberScope $scope,
        private readonly AnnouncementFeed $feed,
    ) {}

    #[OA\Get(
        path: '/api/portal/announcements',
        summary: 'Announcements for the member',
        description: 'Signed-in portal token; the announcements switch. Pinned first, then newest, 20 a page. Rows: {id, title, body, publish_at, expires_at, pinned, read_at}; `meta` {current_page, last_page, per_page, total, unread}.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The announcements'), new OA\Response(response: 403, description: 'Switched off')],
    )]
    public function index(): JsonResponse
    {
        $portalUser = $this->scope->portalUser();
        $page = $this->feed->visibleTo($portalUser->borrower)
            ->orderByDesc('pinned')
            ->orderByRaw('COALESCE(publish_at, created_at) DESC')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
        $items = collect($page->items());
        $reads = $this->feed->readTimes($portalUser, $items);

        return response()->json([
            'success' => true,
            'data' => $items->map(fn (PortalAnnouncement $a) => $this->feed->row($a, $reads[$a->id] ?? null))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread' => $this->feed->unreadCount($portalUser),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/portal/announcements/{id}',
        summary: 'One announcement',
        description: 'Signed-in portal token. 404 when it isn\'t for the member, isn\'t published, hasn\'t started or has expired.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The announcement'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function show(int $id): JsonResponse
    {
        $portalUser = $this->scope->portalUser();
        $announcement = $this->feed->find($portalUser, $id);
        $reads = $this->feed->readTimes($portalUser, collect([$announcement]));

        return response()->json(['success' => true, 'data' => $this->feed->row($announcement, $reads[$announcement->id] ?? null)]);
    }

    #[OA\Post(
        path: '/api/portal/announcements/{id}/read',
        summary: 'Mark an announcement read',
        description: 'Signed-in portal token. Once: reading it again keeps the first time. 404 when it isn\'t for the member.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The announcement, read'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function read(int $id): JsonResponse
    {
        $portalUser = $this->scope->portalUser();
        $announcement = $this->feed->find($portalUser, $id);
        $read = $this->feed->markRead($portalUser, $announcement);

        return response()->json(['success' => true, 'data' => $this->feed->row($announcement, $read->read_at)]);
    }
}
