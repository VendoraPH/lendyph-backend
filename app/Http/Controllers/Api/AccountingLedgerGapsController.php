<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Traits\ScopesBranchFilter;
use App\Http\Controllers\Controller;
use App\Services\Accounting\LedgerGapsReport;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * The ledger gaps report (Phase 2, Accounting decision 13): past events that
 * posted nothing, or posted differently from today's rules. Read only; the
 * figures and the totals come from {@see LedgerGapsReport}, so the screen
 * never adds anything up.
 */
class AccountingLedgerGapsController extends Controller
{
    use ScopesBranchFilter;

    public function __construct(private LedgerGapsReport $report) {}

    #[OA\Get(
        path: '/api/accounting/ledger-gaps',
        summary: 'Past events that posted nothing, or posted differently from today\'s rules',
        description: 'The paginator envelope ({data, links, meta}), 25 rows a page. Each row: `kind`, `kind_label`, `record_id`, `reference`, `date`, `branch` ({id, name} or null), `member` ({name, code} or null for a walk-in), `amount` (pesos, two decimals, as a string), `posted` (`none` or `differently`), `reason` and `imported` (the loan came in by CSV). `meta.totals` holds the overall `count` and, for every kind, its `count` and `amount` over every page the filters match. `meta.books_kept` is false, with `meta.notice`, when the organisation has no chart of accounts; the list is then empty. Nothing is written.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'kind', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: LedgerGapsReport::KINDS)),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'One of the caller\'s branches (another answers 403). Without it, every branch the caller holds; rows with no branch (walk-ins) only for branches:view_all.', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'imported', in: 'query', required: false, description: 'Events of loans imported by CSV: `include` (default), `exclude`, or `only`.', schema: new OA\Schema(type: 'string', enum: LedgerGapsReport::IMPORTED_FILTERS, default: 'include')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'One page of gaps, with the totals'),
            new OA\Response(response: 403, description: 'Missing accounting:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'Unknown kind or imported filter, or a bad date range'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('accounting:view');

        $filters = request()->validate([
            'kind' => ['nullable', 'string', Rule::in(LedgerGapsReport::KINDS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer'],
            'imported' => ['nullable', 'string', Rule::in(LedgerGapsReport::IMPORTED_FILTERS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $branches = $this->branchScope($filters['branch_id'] ?? null);
        $page = (int) ($filters['page'] ?? 1);
        $found = $this->report->page($filters, $branches, $page);
        $booksKept = $this->report->booksKept();

        $paginator = new LengthAwarePaginator($found['rows'], $found['total'], LedgerGapsReport::PER_PAGE, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);

        return JsonResource::collection($paginator)->additional(['meta' => [
            'totals' => $this->report->totals($filters, $branches),
            'books_kept' => $booksKept,
            'notice' => $booksKept ? null : LedgerGapsReport::NO_BOOKS_NOTICE,
        ]]);
    }
}
