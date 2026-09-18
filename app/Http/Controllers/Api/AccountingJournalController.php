<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ReverseJournalRequest;
use App\Http\Requests\Accounting\StoreJournalRequest;
use App\Http\Requests\Accounting\UpdateJournalRequest;
use App\Http\Resources\JournalEntryResource;
use App\Models\AccountingJournal;
use App\Services\Accounting\JournalPoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
    public function __construct(private JournalPoster $poster) {}

    #[OA\Get(
        path: '/api/accounting/journals',
        summary: 'List journal entries',
        description: 'The journal register, newest first. Returns the raw Laravel paginator envelope ({data, links, meta}); clients drain it by following `meta.last_page`. `per_page` is clamped at 100 and defaults to 15.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'posted', 'reversed'])),
            new OA\Parameter(name: 'source', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated journal register'),
            new OA\Response(response: 403, description: 'Missing journals:view'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('journals:view');

        $filters = request()->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(AccountingJournal::STATUSES)],
            'source' => ['nullable', Rule::in(AccountingJournal::SOURCES)],
            'branch_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            // `min:1` matters: `?per_page=0` reaches Builder::paginate() as
            // `$perPage ?: $model->getPerPage()` and silently becomes 15, while
            // a negative value reaches the database as a negative LIMIT.
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        $journals = AccountingJournal::query()
            ->withRegisterRelations()
            ->when(isset($filters['from']), fn ($q) => $q->where('date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->where('date', '<=', $filters['to']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['source']), fn ($q) => $q->where('source', $filters['source']))
            ->when(isset($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            // Newest first, and `id` as the tiebreak so two entries on the same
            // date do not swap places between pages — which would show one
            // twice and hide the other.
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return JournalEntryResource::collection($journals);
    }

    #[OA\Post(
        path: '/api/accounting/journals',
        summary: 'Create a manual journal entry (draft)',
        description: 'Creates a DRAFT. `source` is forced to `manual` on this route. `total_debit`/`total_credit` are accepted and ignored — they are recomputed from the persisted lines when the entry posts. Every line needs exactly one non-zero side, in integer CENTAVOS, and an entry needs at least two lines.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['date', 'description', 'lines'],
                properties: [
                    new OA\Property(property: 'date', type: 'string', format: 'date'),
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
            new OA\Response(response: 422, description: 'Validation error'),
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

    #[OA\Get(
        path: '/api/accounting/journals/{id}',
        summary: 'Show a journal entry',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The entry, with its lines'),
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
            new OA\Response(response: 422, description: 'Validation error, or the entry is already posted'),
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
            new OA\Response(response: 422, description: 'Already posted, unbalanced, single-line, or referencing an unpostable account'),
        ],
    )]
    public function post(AccountingJournal $journal): JsonResponse
    {
        $this->authorize('journals:post');

        $posted = $this->poster->post($journal, request()->user()?->id);

        return $this->respond($posted);
    }

    #[OA\Post(
        path: '/api/accounting/journals/{id}/reverse',
        summary: 'Reverse a posted entry',
        description: "Writes a MIRROR entry — every debit becomes a credit against the same account — built from the original's stored lines, and posts it. The original is not edited beyond `status` and `reversed_by_journal_id`, so both halves stay on the record and the pair nets to zero on every statement. Returns the REVERSAL, not the original. Refused with 422 on a draft or on an entry already reversed.",
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'date', type: 'string', format: 'date', description: 'Where the reversal lands. Defaults to today — routinely NOT the original\'s date, or a period already reported on would change.'),
                new OA\Property(property: 'reason', type: 'string', maxLength: 300, description: 'Folded into the reversal description; there is no column for it.'),
            ]),
        ),
        responses: [
            new OA\Response(response: 201, description: 'The posted reversing entry'),
            new OA\Response(response: 403, description: 'Missing journals:reverse'),
            new OA\Response(response: 422, description: 'Not posted, or already reversed'),
        ],
    )]
    public function reverse(ReverseJournalRequest $request, AccountingJournal $journal): JsonResponse
    {
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
        ]);

        return (new JournalEntryResource($journal))->response()->setStatusCode($status);
    }
}
