<?php

namespace App\Http\Controllers\Api\Savings;

use App\Http\Controllers\Controller;
use App\Services\Savings\AutoCredit\RunHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Past savings auto-credit runs (gap round B7), read only, `savings:view`.
 * Head office (`branches:view_all`) sees every run; a branch user sees the
 * runs that posted to or skipped their branches' accounts, with the counts
 * and totals of those accounts only. Another run answers 404.
 */
class SavingsAutoCreditRunController extends Controller
{
    public function __construct(private readonly RunHistory $history) {}

    #[OA\Get(
        path: '/api/savings/auto-credit/runs',
        summary: 'Past savings auto-credit runs',
        description: 'Needs `savings:view`. Newest first, paged (`per_page` up to 100, 15 by default). Each row: `cycle_date`, `cycle_label`, `posted_on`, `method` and `method_label`, `reference_number`, `settlement_account` {id, code, name} (the account its deposits debited; null when it credited nothing or no books are kept), `posted_count`, `posted_total` (₱, 2 decimals), `skipped_count`, `skips_recorded` and `run_by` {id, name}. A branch user sees only runs that touched their branches\' accounts, with those accounts\' counts and totals; on a run from before skips were recorded their `skipped_count` is null.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: '{data: run[], links, meta}'),
            new OA\Response(response: 403, description: 'Missing savings:view'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('savings:view');

        $page = $this->history->page($request->user(), min(max((int) $request->query('per_page', 15), 1), 100));

        return response()->json([
            'data' => $this->history->rows($page->getCollection(), $request->user()),
            'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    #[OA\Get(
        path: '/api/savings/auto-credit/runs/{run}',
        summary: 'One past savings auto-credit run',
        description: 'Needs `savings:view`. The row of the list plus `posted` (account, member, amount, transaction, reference and status: credited, or credited then reversed) and `skipped` (account, member, amount and the reason in words; null on a run from before skips were recorded), limited to the caller\'s branches.',
        tags: ['Savings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: '{success, data: run}'),
            new OA\Response(response: 403, description: 'Missing savings:view'),
            new OA\Response(response: 404, description: 'No such run, or none of its accounts is in the caller\'s branches'),
        ],
    )]
    public function show(Request $request, int $run): JsonResponse
    {
        $this->authorize('savings:view');

        return response()->json(['success' => true, 'data' => $this->history->detail($this->history->find($request->user(), $run), $request->user())]);
    }
}
