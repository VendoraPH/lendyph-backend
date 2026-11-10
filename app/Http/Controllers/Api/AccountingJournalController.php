<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\PostJournalRequest;
use App\Http\Requests\Accounting\PreviewJournalRequest;
use App\Http\Requests\Accounting\ReverseJournalRequest;
use App\Http\Requests\Accounting\StoreJournalRequest;
use App\Http\Requests\Accounting\UpdateJournalRequest;
use App\Http\Resources\JournalEntryResource;
use App\Models\AccountingJournal;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\JournalPreview;
use App\Services\Accounting\YearEndCloser;
use App\Services\BranchAccess;
use App\Services\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Journal entries: drafting, posting and reversing.
 *
 * Nothing in this class writes a journal. Every write goes through
 * {@see JournalPoster}, which is the only thing that knows how to produce a
 * valid one — allocating the number under a lock, recomputing the totals from
 * the persisted lines, and re-checking every account is still postable at the
 * moment of posting rather than at the moment of drafting.
 *
 * Posting and reversing are separate VERBS rather than a status field on the
 * update, because a posted entry is immutable: the only correction is a second,
 * mirrored entry, and `PUT /journals/{id}` refuses a posted one outright.
 */
class AccountingJournalController extends Controller
{
    public function __construct(
        private JournalPoster $poster,
        private YearEndCloser $yearEnd,
    ) {}

    #[OA\Get(
        path: '/api/accounting/journals',
        summary: 'List journal entries',
        description: 'The journal register, newest first. Returns the raw Laravel paginator envelope ({data, links, meta}); clients drain it by following `meta.last_page`. `per_page` is clamped at 100 and defaults to 15. Each row carries `reverses_journal_no`: on a reversal, the number of the entry it reverses; null on every other entry.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'posted', 'reversed'])),
            new OA\Parameter(
                name: 'source',
                in: 'query',
                required: false,
                description: 'Any value of the column enum. Ten are ever written: loan_release, loan_collection, loan_fee, gcash, expense, payable, transfer, credit_loss, manual, reversal. The rest are accepted and return an empty page — notably `penalty`, which is reserved by design because penalties post as a line inside the loan_collection entry rather than as an entry of their own.',
                schema: new OA\Schema(type: 'string', enum: AccountingJournal::SOURCES),
            ),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Journal number, reference or description, anywhere in the text. `%`, `_` and `\\` match literally.', schema: new OA\Schema(type: 'string', maxLength: 100)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated journal register. `meta.totals` cover every matching journal, not the page: `count`, and `total_debit` and `total_credit` in integer centavos (a draft counts its lines). `meta.stats` counts journals per status with every filter except `status`.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'meta', type: 'object', properties: [
                        new OA\Property(property: 'totals', type: 'object', properties: [
                            new OA\Property(property: 'count', type: 'integer'),
                            new OA\Property(property: 'total_debit', type: 'integer', description: 'Centavos.'),
                            new OA\Property(property: 'total_credit', type: 'integer', description: 'Centavos.'),
                        ]),
                        new OA\Property(property: 'stats', type: 'object', properties: [
                            new OA\Property(property: 'draft', type: 'integer'),
                            new OA\Property(property: 'posted', type: 'integer'),
                            new OA\Property(property: 'reversed', type: 'integer'),
                        ]),
                    ]),
                ]),
            ),
            new OA\Response(response: 403, description: 'Missing journals:view, or a branch_id outside the caller\'s branches'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('journals:view');

        $filters = request()->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(AccountingJournal::STATUSES)],
            /*
             * Every enum value, INCLUDING the eight nothing writes.
             *
             * `?source=penalty` is accepted and returns an empty page, which
             * looks like a bug and is not one. Penalties are cash basis — they
             * are a LINE inside the `loan_collection` journal, never a journal
             * of their own — so "no entries have source=penalty" is the true
             * answer to the question asked, and 422 would instead claim the
             * question is invalid when the column plainly permits the value.
             * {@see AccountingJournal::SOURCES} names all eight and why.
             *
             * Narrowing this list to the ten that are written would also break
             * twice over: `opening_balance` is being implemented right now and
             * would 422 the day it ships, and any historical row on any of the
             * ten deployments holding one of these values would become
             * unfilterable while still appearing in the unfiltered register —
             * a read API refusing to describe data it is showing.
             *
             * Rule::in still does the job validation owes here: it bounds the
             * value to the enum and rejects everything else.
             */
            'source' => ['nullable', Rule::in(AccountingJournal::SOURCES)],
            'branch_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            // `min:1` matters: `?per_page=0` reaches Builder::paginate() as
            // `$perPage ?: $model->getPerPage()` and silently becomes 15, while
            // a negative value reaches the database as a negative LIMIT.
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        app(BranchAccess::class)->authorizeBranchFilter(request()->user(), $filters['branch_id'] ?? null);

        $search = trim((string) ($filters['search'] ?? ''));

        // Every filter but `status`, so the status counts can share it.
        $filtered = fn (): Builder => $this->visibleToCaller(AccountingJournal::query())
            ->when(isset($filters['from']), fn ($q) => $q->where('date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->where('date', '<=', $filters['to']))
            ->when(isset($filters['source']), fn ($q) => $q->where('source', $filters['source']))
            ->when(isset($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when($search !== '', function (Builder $q) use ($search): void {
                $like = LikePattern::contains($search);

                $q->where(fn (Builder $w) => $w
                    ->where('journal_no', 'like', $like)
                    ->orWhere('reference', 'like', $like)
                    ->orWhere('description', 'like', $like));
            });

        $matching = fn (): Builder => $filtered()
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']));

        $journals = $matching()
            ->withRegisterRelations()
            // Newest first, and `id` as the tiebreak so two entries on the same
            // date do not swap places between pages — which would show one
            // twice and hide the other.
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return JournalEntryResource::collection($journals)
            ->additional(['meta' => [
                'totals' => $this->registerTotals($matching()),
                'stats' => $this->statusCounts($filtered()),
            ]]);
    }

    #[OA\Post(
        path: '/api/accounting/journals',
        summary: 'Create a manual journal entry (draft)',
        description: 'Creates a DRAFT. `source` is forced to `manual` on this route. `total_debit`/`total_credit` are accepted and ignored — they are recomputed from the persisted lines when the entry posts. Every line needs exactly one non-zero side, in integer CENTAVOS, and an entry needs at least two lines. `date` is a plain `YYYY-MM-DD`; a date with a time or an offset is refused. An entry with no branch that has a line on the allowance account (the `allowance_credit_losses` mapping, 1200 by default) can\'t be dated after today in Manila.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['date', 'description', 'lines'],
                properties: [
                    new OA\Property(property: 'date', type: 'string', format: 'date', example: '2026-11-10', description: 'A plain day, `YYYY-MM-DD`, with no time or offset.'),
                    new OA\Property(property: 'reference', type: 'string', nullable: true, maxLength: 64),
                    new OA\Property(property: 'description', type: 'string', maxLength: 500),
                    new OA\Property(property: 'branch_id', type: 'integer', nullable: true),
                    new OA\Property(
                        property: 'lines',
                        type: 'array',
                        minItems: 2,
                        items: new OA\Items(
                            required: ['account_id', 'debit', 'credit'],
                            properties: [
                                new OA\Property(property: 'account_id', type: 'integer'),
                                new OA\Property(property: 'description', type: 'string', nullable: true),
                                new OA\Property(property: 'debit', type: 'integer', description: 'Centavos. Zero when this line is a credit.'),
                                new OA\Property(property: 'credit', type: 'integer', description: 'Centavos. Zero when this line is a debit.'),
                            ],
                        ),
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Draft created'),
            new OA\Response(response: 403, description: 'Missing journals:create'),
            new OA\Response(response: 422, description: 'Validation error, in words: a date that isn\'t a plain `YYYY-MM-DD`, or an entry with no branch and a line on the allowance account dated after today in Manila (`errors.date`)'),
        ],
    )]
    public function store(StoreJournalRequest $request): JsonResponse
    {
        $journal = $this->poster->draft(
            $request->journalAttributes(),
            $request->journalLines(),
            $request->user()?->id,
        );

        return $this->respond($journal, 201);
    }

    #[OA\Post(
        path: '/api/accounting/journals/preview',
        summary: 'Preview a journal entry\'s totals',
        description: 'The entry form\'s totals, summed on the server exactly as posting sums them, so the preview of a draft\'s lines equals what saving and posting it would record. Integer CENTAVOS. Writes nothing. Lines may be incomplete: there is no two-line minimum, `account_id` is optional and not checked, and a blank row (0 and 0) is counted as a line. Each side is an integer from 0 to Money::maxCentavos(), and an entry carries at most JournalPoster::MAX_LINES lines. `is_balanced` compares the two totals only; posting also needs two lines and a non-zero total.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'lines',
                        type: 'array',
                        maxItems: JournalPoster::MAX_LINES,
                        items: new OA\Items(
                            required: ['debit', 'credit'],
                            properties: [
                                new OA\Property(property: 'account_id', type: 'integer', nullable: true, description: 'Optional, and not checked.'),
                                new OA\Property(property: 'debit', type: 'integer', minimum: 0, maximum: 9007199254740992, description: 'Centavos.'),
                                new OA\Property(property: 'credit', type: 'integer', minimum: 0, maximum: 9007199254740992, description: 'Centavos.'),
                            ],
                        ),
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The totals',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'total_debit', type: 'integer', description: 'Centavos.'),
                        new OA\Property(property: 'total_credit', type: 'integer', description: 'Centavos.'),
                        new OA\Property(property: 'difference', type: 'integer', description: 'total_debit − total_credit, in centavos; negative when the credits are heavier.'),
                        new OA\Property(property: 'out_by', type: 'integer', description: 'The size of the difference, in centavos.'),
                        new OA\Property(property: 'heavier_side', type: 'string', enum: ['debit', 'credit'], nullable: true),
                        new OA\Property(property: 'is_balanced', type: 'boolean'),
                        new OA\Property(property: 'line_count', type: 'integer'),
                    ]),
                ]),
            ),
            new OA\Response(response: 403, description: 'Missing journals:create'),
            new OA\Response(response: 422, description: 'A side that is not a whole number of centavos from 0 to the ceiling, or too many lines'),
        ],
    )]
    public function preview(PreviewJournalRequest $request): JsonResponse
    {
        return response()->json(['data' => JournalPreview::summarise($request->previewLines())]);
    }

    #[OA\Get(
        path: '/api/accounting/journals/{id}',
        summary: 'Show a journal entry',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The entry, with its lines',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'reverses_journal_id', type: 'integer', nullable: true, description: 'On a reversal, the entry it reverses.'),
                        new OA\Property(property: 'reverses_journal_no', type: 'string', nullable: true, example: 'JE-000042', description: 'On a reversal, the number of the entry it reverses; null on every other entry.'),
                        new OA\Property(property: 'reversed_by_journal_id', type: 'integer', nullable: true, description: 'The reversal that undid this entry.'),
                    ]),
                ]),
            ),
            new OA\Response(response: 403, description: 'Missing journals:view'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(AccountingJournal $journal): JsonResponse
    {
        $this->authorize('journals:view');

        return $this->respond($journal);
    }

    #[OA\Put(
        path: '/api/accounting/journals/{id}',
        summary: 'Update a draft journal entry',
        description: 'DRAFTS ONLY. A posted or reversed entry is refused with 422 — correcting one means posting a reversal, which leaves both halves on the record. The lines are replaced wholesale rather than patched: they only mean anything as a set, because they have to balance together.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 200, description: 'Draft updated'),
            new OA\Response(response: 403, description: 'Missing journals:create'),
            new OA\Response(response: 422, description: 'Validation error, or the entry is already posted. The same date rules as create: a plain `YYYY-MM-DD`, and no date after today in Manila for an entry with no branch and a line on the allowance account (`errors.date`)'),
        ],
    )]
    public function update(UpdateJournalRequest $request, AccountingJournal $journal): JsonResponse
    {
        $updated = $this->poster->updateDraft(
            $journal,
            $request->journalAttributes(),
            $request->journalLines(),
        );

        return $this->respond($updated);
    }

    #[OA\Post(
        path: '/api/accounting/journals/{id}/post',
        summary: 'Post a draft into the books',
        description: 'The point of no return. Allocates the next `JE-` number under a row lock, recomputes both totals from the persisted lines, and refuses unless the entry has at least two lines, balances, records a non-zero amount, and every account on it is STILL postable — an account can be deactivated between the draft and the post. After this the entry can only be reversed.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The posted entry, carrying its new journal_no'),
            new OA\Response(response: 403, description: 'Missing journals:post'),
            new OA\Response(response: 422, description: 'Already posted, unbalanced, single-line, or referencing an unpostable account; or, in words on `errors.date`, a draft with no branch and a line on the allowance account dated after today in Manila (the same rule as create)'),
        ],
    )]
    public function post(PostJournalRequest $request, AccountingJournal $journal): JsonResponse
    {
        $posted = $this->poster->post($journal, $request->user()?->id);

        return $this->respond($posted);
    }

    #[OA\Post(
        path: '/api/accounting/journals/{id}/reverse',
        summary: 'Reverse a posted entry',
        description: "Writes a MIRROR entry — every debit becomes a credit against the same account — built from the original's stored lines, and posts it. The original is not edited beyond `status` and `reversed_by_journal_id`, so both halves stay on the record and the pair nets to zero on every statement. Returns the REVERSAL, not the original. Refused with 422 on a draft, on an entry already reversed, and on a year-end `closing` entry or the reversal of one (reopen the year instead).",
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'date', type: 'string', format: 'date', description: 'Where the reversal lands. Defaults to today — routinely NOT the original\'s date, or a period already reported on would change. A time or an offset is read as its day in Manila. Never before the entry\'s own date, and never after today when the entry has no branch and a line on the allowance account.'),
                new OA\Property(property: 'reason', type: 'string', maxLength: 300, description: 'Folded into the reversal description; there is no column for it.'),
            ]),
        ),
        responses: [
            new OA\Response(response: 201, description: 'The posted reversing entry; its `reverses_journal_no` is the original\'s number'),
            new OA\Response(response: 403, description: 'Missing journals:reverse'),
            new OA\Response(response: 422, description: 'In words: not posted, already reversed, or part of a year-end close; a reversal dated before the entry\'s own date, given or by default (`errors.date`); or, for an entry with no branch and a line on the allowance account, a reversal dated after today in Manila (`errors.date`; the entry\'s own date is always allowed, since the pair cancels out on it, and the message names the dates that are)'),
        ],
    )]
    public function reverse(ReverseJournalRequest $request, AccountingJournal $journal): JsonResponse
    {
        // A closing entry is undone by reopening its year, never by hand.
        $this->yearEnd->assertNotPartOfAClose($journal);

        $reversal = $this->poster->reverse(
            $journal,
            $request->input('date') ?: now()->toDateString(),
            $request->input('reason'),
            $request->user()?->id,
        );

        return $this->respond($reversal, 201);
    }

    /**
     * A single entry in the `{"data": {...}}` envelope `api.get`/`api.post`
     * unwrap, with the relations the resource needs to emit display names and
     * denormalised account codes instead of bare ids.
     */
    private function respond(AccountingJournal $journal, int $status = 200): JsonResponse
    {
        $journal->load([
            'lines.account:id,code,name',
            'branch:id,name',
            'creator:id,first_name,last_name',
            'poster:id,first_name,last_name',
            'reverses:id,journal_no',
        ]);

        // `postable_label` is whenLoaded-guarded, so without this the field
        // would be absent here and present on the register — the same resource
        // answering two different shapes. Not part of the `load()` above on
        // purpose: see AccountingJournal::attachPostables().
        $journal->loadPostable();

        return (new JournalEntryResource($journal))->response()->setStatusCode($status);
    }

    /**
     * `{count, total_debit, total_credit}` over every journal the register
     * matches, not the page: one aggregate over those journals' lines, summed in
     * SQL. A draft stores no totals until it posts and a posted entry's totals
     * are recomputed from its lines, so the lines are the one source for both
     * (JournalEntryResource::totalOf()).
     *
     * @param  Builder<AccountingJournal>  $matching
     * @return array{count: int, total_debit: int, total_credit: int}
     */
    private function registerTotals(Builder $matching): array
    {
        $ids = (clone $matching)->select('accounting_journals.id');

        $sums = DB::table('accounting_journal_lines')
            ->whereIn('accounting_journal_id', $ids)
            ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->first();

        return [
            'count' => $matching->count(),
            'total_debit' => (int) $sums->total_debit,
            'total_credit' => (int) $sums->total_credit,
        ];
    }

    /**
     * Journals per status, with every filter except `status`: the register's
     * tab counts.
     *
     * @param  Builder<AccountingJournal>  $filtered
     * @return array{draft: int, posted: int, reversed: int}
     */
    private function statusCounts(Builder $filtered): array
    {
        $counts = $filtered
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'draft' => (int) ($counts['draft'] ?? 0),
            'posted' => (int) ($counts['posted'] ?? 0),
            'reversed' => (int) ($counts['reversed'] ?? 0),
        ];
    }

    /**
     * Rows in the caller's branches (BranchAccess). A row with no branch is
     * visible only to `branches:view_all`.
     *
     * @param  Builder<AccountingJournal>  $query
     * @return Builder<AccountingJournal>
     */
    private function visibleToCaller(Builder $query): Builder
    {
        $branchIds = app(BranchAccess::class)->for(request()->user());

        return $branchIds === null ? $query : $query->whereIn('branch_id', $branchIds);
    }
}
