<?php

namespace App\Http\Controllers\Api\Amla;

use App\Http\Controllers\Controller;
use App\Http\Requests\Amla\DecideScreeningMatchRequest;
use App\Http\Requests\Amla\UploadWatchlistRequest;
use App\Http\Resources\Amla\AmlaScreeningPresenter;
use App\Models\AmlaScreeningMatch;
use App\Models\AmlaWatchlistVersion;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\Amla\Screening\MatchReview;
use App\Services\Amla\Screening\Watchlists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Watchlist and sanctions screening (AMLA-B5; AMLA design §4.11; task §11).
 * Every endpoint needs `amla:screening` and head-office access, checked before
 * any record is looked up. Lists come from uploads only. Every list and view
 * of a match is written to the AMLA log by id; nothing is confirmed by the
 * system, and nothing here notifies anyone.
 */
class AmlaScreeningController extends Controller
{
    public function __construct(private readonly AmlaAccess $access, private readonly AmlaLog $log) {}

    #[OA\Get(
        path: '/api/amla/screening/lists',
        summary: 'Watchlists and their versions',
        description: 'Needs `amla:screening` and `branches:view_all`. Each list (UN Security Council consolidated list, AMLC designations, internal list) with the version in force and the last 20 versions.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The lists'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words')],
    )]
    public function lists(Request $request): JsonResponse
    {
        $this->access->authorizeHeadOffice($request->user(), AmlaAccess::SCREENING);

        $data = [];

        foreach (AmlaWatchlistVersion::SOURCES as $source => $meta) {
            $versions = AmlaWatchlistVersion::query()->where('source', $source)->with('uploader')->orderByDesc('id')->limit(20)->get();
            $active = $versions->firstWhere('status', AmlaWatchlistVersion::ACTIVE);

            $data[] = [
                'source' => $source,
                'source_words' => $meta['words'],
                'format' => $meta['format'],
                'active_version' => $active === null ? null : AmlaScreeningPresenter::version($active),
                'versions' => $versions->map(fn (AmlaWatchlistVersion $version) => AmlaScreeningPresenter::version($version))->values(),
            ];
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    #[OA\Post(
        path: '/api/amla/screening/lists/{source}/versions',
        summary: 'Upload a watchlist version',
        description: 'Needs `amla:screening` and `branches:view_all`. Multipart `file` (the UN list: its XML; amlc and internal: the CSV template reference, type, full_name, aliases (|-separated), birth_year, nationality) and optional `version_label`. A malformed file is refused in words, row by row, and nothing is kept. The new version supersedes the one in force and waits to be rescreened against everyone. No outbound calls.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'The version'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words'), new OA\Response(response: 422, description: 'Unreadable or invalid, in words')],
    )]
    public function upload(UploadWatchlistRequest $request, Watchlists $watchlists, string $source): JsonResponse
    {
        $version = $watchlists->upload($request->user(), $source, $request->file('file'), $request->validated('version_label'));

        return response()->json([
            'success' => true,
            'message' => 'The list is kept. Everyone is screened against it on the next screening run.',
            'data' => AmlaScreeningPresenter::version($version->load('uploader')),
        ], 201);
    }

    #[OA\Post(
        path: '/api/amla/screening/rescreen',
        summary: 'Rescreen everyone against the lists in force',
        description: 'Needs `amla:screening` and `branches:view_all`. Marks each list in force to be rescreened on the next screening run (while monitoring is on). 422 when no list is in force.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 202, description: 'Queued'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words'), new OA\Response(response: 422, description: 'No list in force')],
    )]
    public function rescreen(Request $request): JsonResponse
    {
        $this->access->authorizeHeadOffice($request->user(), AmlaAccess::SCREENING);

        $active = AmlaWatchlistVersion::query()->where('status', AmlaWatchlistVersion::ACTIVE)->get();

        abort_if($active->isEmpty(), 422, 'No list is in force yet. Upload one first.');

        foreach ($active as $version) {
            if ($version->rescreen_status !== AmlaWatchlistVersion::RESCREEN_PENDING) {
                $version->forceFill(['rescreen_status' => AmlaWatchlistVersion::RESCREEN_PENDING])->save();
            }
        }

        $this->log->record('screening_rescreen_requested', $request->user(), details: ['versions' => $active->pluck('id')->all()]);

        return response()->json(['success' => true, 'message' => 'Everyone will be rescreened on the next screening run.', 'data' => ['queued_versions' => $active->count()]], 202);
    }

    #[OA\Get(
        path: '/api/amla/screening/matches',
        summary: 'Potential matches',
        description: 'Needs `amla:screening` and `branches:view_all`. Newest first, 25 a page. Filters: `status` (pending, confirmed, cleared), `subject_type` (member, walk_in, co_maker). Logged to the AMLA log.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The matches'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words')],
    )]
    public function matches(Request $request): JsonResponse
    {
        $this->access->authorizeHeadOffice($request->user(), AmlaAccess::SCREENING);

        $filters = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(array_keys(AmlaScreeningMatch::STATUSES))],
            'subject_type' => ['sometimes', 'string', Rule::in(array_keys(AmlaScreeningPresenter::SUBJECT_TYPES))],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $this->log->record('screening_matches_listed', $request->user(), details: array_intersect_key($filters, array_flip(['status', 'subject_type', 'page'])));

        $page = AmlaScreeningMatch::query()
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['subject_type']), fn ($query) => $query->where('subject_type', $filters['subject_type']))
            ->with('entry')
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (AmlaScreeningMatch $match) => AmlaScreeningPresenter::match($match, false))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    #[OA\Get(
        path: '/api/amla/screening/matches/{id}',
        summary: 'One potential match',
        description: 'Needs `amla:screening` and `branches:view_all`, checked before the lookup. The subject, the listing, the score, threshold and reasons, and the decision. Logged to the AMLA log.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The match'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function match(Request $request, int $id): JsonResponse
    {
        $this->access->authorizeHeadOffice($request->user(), AmlaAccess::SCREENING);
        $match = AmlaScreeningMatch::query()->with('entry', 'version', 'decider')->findOrFail($id);

        $this->log->record('screening_match_viewed', $request->user(), 'screening_match', $match->id, ['match_id' => $match->id]);

        return response()->json(['success' => true, 'data' => AmlaScreeningPresenter::match($match, true)]);
    }

    #[OA\Post(
        path: '/api/amla/screening/matches/{id}/confirm',
        summary: 'Confirm a potential match',
        description: 'Needs `amla:screening` and `branches:view_all`. `reason` required. Once: a decided match answers 409. Records the finding only; it places no hold and files nothing.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Confirmed'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words'), new OA\Response(response: 409, description: 'Already decided')],
    )]
    public function confirm(DecideScreeningMatchRequest $request, MatchReview $review, int $id): JsonResponse
    {
        return $this->decided($review->decide($request->user(), $id, AmlaScreeningMatch::CONFIRMED, (string) $request->validated('reason')));
    }

    #[OA\Post(
        path: '/api/amla/screening/matches/{id}/clear',
        summary: 'Clear a potential match as a false positive',
        description: 'Needs `amla:screening` and `branches:view_all`. `reason` required. Once: a decided match answers 409.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Cleared'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words'), new OA\Response(response: 409, description: 'Already decided')],
    )]
    public function clear(DecideScreeningMatchRequest $request, MatchReview $review, int $id): JsonResponse
    {
        return $this->decided($review->decide($request->user(), $id, AmlaScreeningMatch::CLEARED, (string) $request->validated('reason')));
    }

    private function decided(AmlaScreeningMatch $match): JsonResponse
    {
        return response()->json(['success' => true, 'data' => AmlaScreeningPresenter::match($match->fresh(['entry', 'version', 'decider']), true)]);
    }
}
