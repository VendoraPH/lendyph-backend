<?php

namespace App\Http\Controllers\Api\Amla;

use App\Http\Controllers\Controller;
use App\Http\Requests\Amla\AmlaLogIndexRequest;
use App\Http\Resources\Amla\AmlaPresenter;
use App\Models\AmlaLogEntry;
use App\Services\Amla\Foundation\AmlaLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * AMLA's own log (AMLA design §3.6, §4.14), read with `amla:audit` and
 * head-office access. Reading it is itself logged (`log_viewed`).
 */
class AmlaLogController extends Controller
{
    #[OA\Get(
        path: '/api/amla/log',
        summary: 'AMLA log',
        description: 'Needs `amla:audit` and `branches:view_all`. Newest first, 50 a page (`per_page` up to 100). Filters: `action`, `user_id`, `subject_type`, `subject_id`, `from` and `to` (Manila dates). Kept forever, never the main audit trail. Each read writes a `log_viewed` row first.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The log'), new OA\Response(response: 403, description: 'Missing amla:audit or head-office access, in words'), new OA\Response(response: 422, description: 'Bad filter')],
    )]
    public function index(AmlaLogIndexRequest $request, AmlaLog $log): JsonResponse
    {
        $filters = $request->validated();
        $log->record('log_viewed', $request->user(), details: array_intersect_key($filters, array_flip(['action', 'user_id', 'subject_type', 'subject_id', 'from', 'to', 'page'])));

        $page = AmlaLogEntry::query()
            ->when(isset($filters['action']), fn ($query) => $query->where('action', $filters['action']))
            ->when(isset($filters['user_id']), fn ($query) => $query->where('user_id', $filters['user_id']))
            ->when(isset($filters['subject_type']), fn ($query) => $query->where('subject_type', $filters['subject_type']))
            ->when(isset($filters['subject_id']), fn ($query) => $query->where('subject_id', $filters['subject_id']))
            ->when(isset($filters['from']), fn ($query) => $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'], 'Asia/Manila')->startOfDay()))
            ->when(isset($filters['to']), fn ($query) => $query->where('created_at', '<=', CarbonImmutable::parse($filters['to'], 'Asia/Manila')->endOfDay()))
            ->with('user')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 50));
        $references = AmlaLog::references($page->items());

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (AmlaLogEntry $entry) => AmlaPresenter::logEntry($entry, $references))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }
}
