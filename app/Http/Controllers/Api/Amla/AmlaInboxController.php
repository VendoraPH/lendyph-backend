<?php

namespace App\Http\Controllers\Api\Amla;

use App\Http\Controllers\Controller;
use App\Http\Resources\Amla\AmlaCasePresenter;
use App\Models\AmlaInboxItem;
use App\Services\Amla\Foundation\AmlaAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

/**
 * AMLA's own inbox (AMLA-B6): any AMLA key, and only the caller's own items,
 * shown in AMLA screens. Another user's item answers 404.
 */
class AmlaInboxController extends Controller
{
    public function __construct(private readonly AmlaAccess $access) {}

    #[OA\Get(path: '/api/amla/inbox', summary: 'My AMLA inbox', description: 'Needs any AMLA key. The caller\'s latest 50 items, unread first; `unread` counts them.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The items')])]
    public function index(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), AmlaAccess::PERMISSIONS);
        $items = AmlaInboxItem::query()->where('user_id', $request->user()->id);

        return response()->json([
            'success' => true,
            'data' => (clone $items)->orderByRaw('read_at is not null')->orderByDesc('id')->limit(50)->get()
                ->map(fn (AmlaInboxItem $item) => AmlaCasePresenter::inboxItem($item))->values()->all(),
            'meta' => ['unread' => (clone $items)->whereNull('read_at')->count()],
        ]);
    }

    #[OA\Post(path: '/api/amla/inbox/{id}/read', summary: 'Mark an AMLA inbox item read', description: 'Needs any AMLA key; the caller\'s own item only.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The item')])]
    public function read(Request $request, int $id): JsonResponse
    {
        $this->access->authorize($request->user(), AmlaAccess::PERMISSIONS);
        $item = AmlaInboxItem::query()->where('user_id', $request->user()->id)->whereKey($id)->first() ?? abort(404);

        if ($item->read_at === null) {
            $item->forceFill(['read_at' => Carbon::now()])->save();
        }

        return response()->json(['success' => true, 'data' => AmlaCasePresenter::inboxItem($item)]);
    }
}
