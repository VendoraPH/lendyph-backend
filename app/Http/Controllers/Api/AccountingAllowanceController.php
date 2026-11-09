<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\PostAllowanceRequest;
use App\Models\AccountingAllowancePosting;
use App\Services\Accounting\AllowancePoster;
use App\Services\BranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * The allowance for credit losses (decision 12): a preview, the permissioned
 * posting, and the postings list. Integer CENTAVOS. Every endpoint needs
 * `branches:view_all`: the run is organisation-wide, one posting per branch.
 */
class AccountingAllowanceController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    public function __construct(private AllowancePoster $allowance) {}

    #[OA\Get(
        path: '/api/accounting/allowance/preview',
        summary: 'Allowance for credit losses: what a run would post',
        description: 'Answers `{data}` in integer CENTAVOS. The base is each past-due loan\'s outstanding principal, in the aging bucket of its oldest unpaid instalment (days from the bare due date, no grace). `rates`: `[{bucket, label, rate_percent}]` for `1_30`, `31_60`, `61_90`, `91_120` and `over_120`; `rate_percent` is a string, never rounded. `branches`: every active branch (and any other with figures), `[{branch: {id, name}, buckets: [{bucket, loan_count, principal, rate_percent, required}], principal, required, current_balance, adjustment}]`. Each bucket\'s `required` is principal × rate, rounded half up to the centavo once. `current_balance` is the branch\'s 1200 balance at `as_of` as a positive credit; `adjustment` = `required` − `current_balance` (positive provides, negative releases). `totals`: `{loan_count, principal, required, current_balance, adjustment}`. `unassigned_balance` is the 1200 balance on journals with no branch, which a run leaves alone. `last_posting`: `{as_of, posted_at, posted_by: {id, name}}` or null. `can_post` and `blocking_reasons[]` (in words).',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'), description: 'YYYY-MM-DD, today or earlier. Defaults to today (Manila).'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The preview'),
            new OA\Response(response: 403, description: 'Missing accounting:view or branches:view_all'),
            new OA\Response(response: 422, description: 'A bad or future date'),
        ],
    )]
    public function preview(): JsonResponse
    {
        $this->authorize('accounting:view');
        $this->authorize(BranchAccess::VIEW_ALL);

        $filters = request()->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString()],
        ], [
            'as_of.before_or_equal' => 'The allowance can\'t be previewed for a date in the future.',
        ]);

        return response()->json([
            'data' => $this->allowance->preview($filters['as_of'] ?? now()->toDateString(), request()->user()),
        ]);
    }

    #[OA\Post(
        path: '/api/accounting/allowance/postings',
        summary: 'Post the allowance for credit losses',
        description: 'Recomputes the preview under the allowance lock and posts one `credit_loss` journal per branch whose `adjustment` isn\'t 0: Dr 5140 Credit Loss Expense / Cr 1200 to provide, the reverse to release. The 1200 balance on journals with no branch is never adjusted. Answers 201 `{data: {run_id, as_of, postings: [{id, branch: {id, name}, required, previous_balance, adjustment, journal: {id, journal_no} | null}], totals: {required, previous_balance, adjustment}}}` in integer CENTAVOS; `totals` add up the posted rows.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['as_of', 'expected_adjustment'],
            properties: [
                new OA\Property(property: 'as_of', type: 'string', format: 'date', description: 'Today or earlier'),
                new OA\Property(property: 'expected_adjustment', type: 'integer', description: 'The preview\'s totals.adjustment, signed centavos'),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'The run'),
            new OA\Response(response: 403, description: 'Missing accounting:allowance or branches:view_all'),
            new OA\Response(response: 409, description: '"The figures changed since the preview; review them again", or another allowance write at the same time'),
            new OA\Response(response: 422, description: 'Nothing changes, the period is closed, the date is in the future, or the books or a default account aren\'t set up'),
        ],
    )]
    public function store(PostAllowanceRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->allowance->post(
                (string) $request->validated('as_of'),
                (int) $request->validated('expected_adjustment'),
                $request->user(),
            ),
        ], 201);
    }

    #[OA\Get(
        path: '/api/accounting/allowance/postings',
        summary: 'Allowance postings, newest first',
        description: 'The paginator envelope `{data, links, meta}`, 25 rows by default and at most 100, newest first. Each row: `{id, branch: {id, name}, required, previous_balance, adjustment, journal: {id, journal_no} | null, run_id, as_of, created_by: {id, name}, created_at}`, in integer CENTAVOS. `meta.totals.adjustment` covers every row, not the page.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The paginator envelope with meta.totals'),
            new OA\Response(response: 403, description: 'Missing accounting:view or branches:view_all'),
            new OA\Response(response: 422, description: 'A bad page size'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('accounting:view');
        $this->authorize(BranchAccess::VIEW_ALL);

        $filters = request()->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $page = AccountingAllowancePosting::query()
            ->with(['branch:id,name', 'journal:id,journal_no', 'creator'])
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE))
            ->withQueryString();

        return JsonResource::collection($page->through(fn (AccountingAllowancePosting $row): array => $row->toApiArray(listed: true)))
            ->additional(['meta' => ['totals' => [
                'adjustment' => (int) AccountingAllowancePosting::query()->sum('adjustment'),
            ]]]);
    }
}
