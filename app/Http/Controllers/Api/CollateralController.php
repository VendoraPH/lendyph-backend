<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Collateral\AttachCollateralRequest;
use App\Http\Requests\Collateral\CollateralRegisterRequest;
use App\Http\Requests\Collateral\StoreCollateralRequest;
use App\Http\Requests\Collateral\UpdateCollateralRequest;
use App\Http\Resources\CollateralRegisterGroupResource;
use App\Http\Resources\CollateralResource;
use App\Models\Collateral;
use App\Models\Loan;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\CollateralAttacher;
use App\Services\CollateralRegister;
use App\Services\CollateralWriteTransaction;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class CollateralController extends Controller
{
    /**
     * The loan statuses that fix a collateral's `amount`: signed off and not
     * finished. `approved` has had its security approved and is about to be
     * released against it; the rest still owe. Drafts and applications can
     * still change, and a finished loan (completed, restructured, rejected,
     * void) no longer rests on the figure.
     */
    private const AMOUNT_FIXED_BY_STATUSES = ['approved', ...Loan::COLLECTIBLE_STATUSES];

    #[OA\Get(
        path: '/api/collaterals',
        summary: 'List collaterals',
        description: 'Filterable by borrower_id and collateral_type_id (alias `type`). Each row carries `active_loans`: the loans in an active status currently holding it, so the client never has to fan out over the loan list to work out what is pledged. Each row also carries `effective_value` and `value_unknown`, its value as security, so the client never has to read a share capital ledger per member to value the list.',
        tags: ['Collaterals'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrower_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'Collateral type id', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Collateral list',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Collateral')),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('collaterals:view');

        // `min:1` is not cosmetic — 0 is a valid integer that no row can carry,
        // and it used to reach a `when()` that treats it as absent. Same rule,
        // same reason, as LoanController::index(); see the filled() note below.
        $filters = request()->validate([
            'borrower_id' => ['nullable', 'integer', 'min:1'],
            'collateral_type_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', 'integer', 'min:1'],
        ]);

        /**
         * Pulled out as locals so both filters below can be gated on filled() —
         * PRESENCE — rather than on truthiness.
         *
         * `Builder::when()` skips its callback for any falsy condition, and `0`
         * and `'0'` are falsy. Gating on the value itself therefore dropped
         * `?borrower_id=0` on the floor and answered with the ENTIRE collateral
         * book — unpaginated, every member's assets and their lock state — for a
         * caller who had asked about one member. `/borrowers/0` on the frontend
         * does exactly that, via Number(params.id). This is bit-for-bit the bug
         * LoanController::index() fixed one commit earlier; its reasoning is
         * written out there and was simply never carried across to this list.
         *
         * `collateral_type_id` wins when both type keys are sent, as it did
         * before. `??` is correct here where truthiness was not: it falls
         * through on null (absent) but not on 0, which validation has already
         * refused anyway.
         */
        $borrowerId = $filters['borrower_id'] ?? null;
        $typeId = $filters['collateral_type_id'] ?? $filters['type'] ?? null;

        $collaterals = Collateral::with(['collateralType', 'activeLoans'])
            ->when(filled($borrowerId), fn ($q) => $q->where('borrower_id', $borrowerId))
            ->when(filled($typeId), fn ($q) => $q->where('collateral_type_id', $typeId))
            ->latest()
            ->get();

        return CollateralResource::valuedCollection($collaterals, request()->user());
    }

    #[OA\Get(
        path: '/api/collaterals/register',
        summary: 'Collateral register, grouped by member and paged',
        description: 'The collateral book grouped by member, one page of GROUPS at a time. `search` and `collateral_type_id` filter collateral rows first; each group and its figures then cover only its filtered rows. '
            .'`search` is a case-insensitive substring match over the member\'s name as displayed, the detail and the type name. '
            .'Values are those of `effective_value`: a caller without `share_capital:view` sees share capital rows as `value_unknown`, which are counted in `unknown_count` and left out of every `total_value`. '
            .'A caller without `borrowers:view` sees every member as "Member #{id}", and search and the `member` sort then work on that label, never the real name. '
            .'Groups are ordered by `sort`/`direction`, then by member name ascending, then by member id ascending. `per_page` is clamped to 1..100.',
        tags: ['Collaterals'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Max 100 characters.', schema: new OA\Schema(type: 'string', maxLength: 100)),
            new OA\Parameter(name: 'collateral_type_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'member', enum: CollateralRegister::SORTS)),
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'asc', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15, minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'One page of member groups',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CollateralRegisterGroup')),
                    new OA\Property(property: 'links', type: 'object', properties: [
                        new OA\Property(property: 'first', type: 'string', nullable: true),
                        new OA\Property(property: 'last', type: 'string', nullable: true),
                        new OA\Property(property: 'prev', type: 'string', nullable: true),
                        new OA\Property(property: 'next', type: 'string', nullable: true),
                    ]),
                    new OA\Property(property: 'meta', type: 'object', properties: [
                        new OA\Property(property: 'current_page', type: 'integer'),
                        new OA\Property(property: 'from', type: 'integer', nullable: true),
                        new OA\Property(property: 'last_page', type: 'integer'),
                        new OA\Property(property: 'path', type: 'string'),
                        new OA\Property(property: 'per_page', type: 'integer'),
                        new OA\Property(property: 'to', type: 'integer', nullable: true),
                        new OA\Property(property: 'total', type: 'integer', description: 'Member groups after filtering.'),
                        new OA\Property(property: 'names_hidden', type: 'boolean', description: 'True when the caller lacks `borrowers:view`, so every `borrower_name` is "Member #{id}".'),
                        new OA\Property(property: 'totals', type: 'object', properties: [
                            new OA\Property(property: 'total_collaterals', type: 'integer', description: 'The whole book; ignores search and type.'),
                            new OA\Property(property: 'tagged_to_active_loans', type: 'integer', description: 'The whole book; ignores search and type.'),
                            new OA\Property(property: 'total_value', type: 'number', description: 'Known values of the filtered rows.'),
                            new OA\Property(property: 'unknown_count', type: 'integer', description: 'Filtered rows whose value is unknown.'),
                            new OA\Property(property: 'members', type: 'integer', description: 'Member groups after filtering; equals `meta.total`.'),
                        ]),
                    ]),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function register(CollateralRegisterRequest $request): AnonymousResourceCollection
    {
        $viewer = $request->user();
        $register = new CollateralRegister($viewer, $request->search(), $request->collateralTypeId());

        $groups = $register->groups($request->sort(), $request->direction(), $request->perPage());

        // Valued in one pass for the whole page and then dealt out to their
        // members, so the page's share capital balances cost one query however
        // many members it holds. Valuing group by group would be one per member.
        $rows = CollateralResource::valuedCollection(
            $register->rowsOf($groups->getCollection()->map(fn (object $group): int => (int) $group->borrower_id)->all()),
            $viewer,
        )->collection->groupBy(fn (CollateralResource $row): int => (int) $row->resource->borrower_id);

        $groups->through(function (object $group) use ($rows): object {
            $group->collaterals = CollateralResource::collection($rows->get((int) $group->borrower_id, collect())->values());

            return $group;
        });

        return CollateralRegisterGroupResource::collection($groups)
            ->additional(['meta' => [
                'names_hidden' => $register->namesHidden(),
                'totals' => $register->totals(),
            ]]);
    }

    #[OA\Post(
        path: '/api/collaterals',
        summary: 'Create collateral',
        tags: ['Collaterals'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['borrower_id', 'collateral_type_id', 'amount'],
                properties: [
                    new OA\Property(property: 'borrower_id', type: 'integer', example: 1),
                    new OA\Property(property: 'collateral_type_id', type: 'integer', example: 1),
                    new OA\Property(property: 'detail_value', type: 'string', nullable: true, example: 'TCT-12345'),
                    new OA\Property(property: 'amount', type: 'number', example: 250000),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Collateral created',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Collateral'),
                ]),
            ),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreCollateralRequest $request): JsonResponse
    {
        $collateral = Collateral::create($request->validated());
        $collateral->load(['collateralType', 'activeLoans']);

        return CollateralResource::valued($collateral, $request->user())
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/collaterals/{id}',
        summary: 'Show collateral',
        tags: ['Collaterals'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Collateral details',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Collateral'),
                ]),
            ),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Collateral $collateral): CollateralResource
    {
        $this->authorize('collaterals:view');

        $collateral->load(['collateralType', 'activeLoans']);

        return CollateralResource::valued($collateral, request()->user());
    }

    #[OA\Put(
        path: '/api/collaterals/{id}',
        summary: 'Update collateral',
        description: 'A changed `amount` is recorded as `collateral_value_changed` in the audit log against every loan holding the collateral. The pledges keep the `snapshot_value` they were attached at.',
        tags: ['Collaterals'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Collateral updated',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Collateral'),
                ]),
            ),
            new OA\Response(response: 409, description: 'Another change to this collateral was saved at the same time; nothing was written. Reload and try again'),
            new OA\Response(response: 422, description: 'Validation error, a `borrower_id` change on a collateral attached to a loan, or an `amount` change (to the centavo) on a collateral held by a loan that is approved, released, ongoing or defaulted (on `amount`, naming the loan(s)). Re-sending the unchanged amount is not a change'),
        ],
    )]
    public function update(UpdateCollateralRequest $request, Collateral $collateral): CollateralResource
    {
        $validated = $request->validated();

        // Locked, because the guard below is check-then-act and the thing it
        // checks is written by somebody else's endpoint.
        //
        // Unlocked, the count and the write straddle a window: move C from
        // member A to member B while C is unattached (count 0, allowed) at the
        // same moment as an attach of C to one of A's loans (ownership allowed,
        // C is still A's), and both commit. C ends up registered to B while
        // pledged to A's loan — finding 1's outcome, routed around finding 4's
        // guard. attach() opens by locking this same `collaterals` row, so
        // whichever arrives second now waits and then sees the other's work.
        //
        // The lock is the FIRST statement in the transaction so that every
        // PLAIN read after it, the count inside the guard included, is answered
        // from a post-lock snapshot: under REPEATABLE READ the consistent
        // snapshot is fixed by the first plain SELECT, so counting after
        // locking means counting a post-lock world.
        //
        // An amount change then locks the loans holding the collateral, the
        // collateral first and the loans after it, the order every collateral
        // write takes (see CollateralAttacher).
        //
        // destroy() has the same check-then-act shape and is deliberately left
        // alone: `loan_collaterals.collateral_id` is restrictOnDelete, so a lost
        // race there fails on the foreign key instead of orphaning a pledge.
        $collateral = CollateralWriteTransaction::run(function () use ($collateral, $validated, $request): Collateral {
            $locked = Collateral::whereKey($collateral->getKey())->lockForUpdate()->first();

            if (! $locked) {
                throw ValidationException::withMessages([
                    'collateral' => 'This collateral no longer exists.',
                ]);
            }

            $this->assertBorrowerIsNotBeingReassignedWhilePledged($locked, $validated);

            $previousAmount = (float) $locked->amount;
            $locked->fill($validated);

            // Only a CHANGE to the amount counts, as for `borrower_id`: the edit
            // form PUTs every field on every save, `amount` included, as 250000
            // or "250000.00". And a change as the column will store it. The
            // decimal:2 cast rounds half-up to the centavo exactly as MySQL's
            // decimal(14, 2) does, so 300000.035 is the change to 300000.04
            // that it will be, where float arithmetic (300000.035 * 100 =
            // 30000003.4999…) would call it the 300000.03 already held.
            $holders = $locked->isDirty('amount') ? $this->lockLoansHolding($locked) : null;

            if ($holders !== null) {
                $this->assertAmountIsNotFixedByAHolder($holders);
            }

            $locked->save();

            if ($holders !== null) {
                $this->recordValueChange($locked, $holders, $previousAmount, $request->user());
            }

            return $locked;
        });

        $collateral->load(['collateralType', 'activeLoans']);

        return CollateralResource::valued($collateral, $request->user());
    }

    /**
     * Every loan holding this collateral, in any status, locked FOR UPDATE in
     * id order.
     *
     * The pledges are read plainly, which is safe here: every write of a pledge
     * of this collateral (attach, detach, the restructure copy) holds the
     * collateral's row lock, which this transaction already has. The loans are
     * locked so that none can move into or out of AMOUNT_FIXED_BY_STATUSES
     * between the check and the write.
     *
     * @return EloquentCollection<int, Loan>
     */
    private function lockLoansHolding(Collateral $collateral): EloquentCollection
    {
        $loanIds = $collateral->loans()->allRelatedIds()->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();

        if ($loanIds === []) {
            return new EloquentCollection;
        }

        return Loan::whereKey($loanIds)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * Refuse an `amount` change while a loan in AMOUNT_FIXED_BY_STATUSES holds
     * the collateral.
     *
     * That loan was approved against this figure. Its pledge carries its own
     * `snapshot_value`, which this never touches, but the register and
     * `effective_value` read the live `amount`, so moving it would have them
     * disagree with the security the loan was approved on.
     *
     * @param  EloquentCollection<int, Loan>  $holders
     *
     * @throws ValidationException on `amount`, naming the loan(s)
     */
    private function assertAmountIsNotFixedByAHolder(EloquentCollection $holders): void
    {
        $fixedBy = $holders->filter(fn (Loan $loan): bool => in_array($loan->status, self::AMOUNT_FIXED_BY_STATUSES, true));

        if ($fixedBy->isEmpty()) {
            return;
        }

        $references = $fixedBy->map(fn (Loan $loan): string => CollateralAttacher::reference($loan))->implode(', ');

        throw ValidationException::withMessages([
            'amount' => $fixedBy->count() === 1
                ? "This collateral secures loan {$references}, which is approved or not yet paid off, so its amount cannot be changed."
                : "This collateral secures loans {$references}, which are approved or not yet paid off, so its amount cannot be changed.",
        ]);
    }

    /**
     * One `collateral_value_changed` row per loan holding the collateral,
     * against that loan, so each loan's history shows its security being
     * revalued. With no holder, the Collateral's own `updated` row already
     * says who changed which collateral from what to what.
     *
     * @param  EloquentCollection<int, Loan>  $holders
     */
    private function recordValueChange(Collateral $collateral, EloquentCollection $holders, float $previousAmount, ?User $user): void
    {
        // Through the decimal:2 cast, so the row shows the stored centavo.
        $amount = (float) $collateral->amount;

        foreach ($holders as $loan) {
            AuditLogService::log(
                action: 'collateral_value_changed',
                auditable: $loan,
                oldValues: ['collateral_id' => $collateral->id, 'amount' => $previousAmount],
                newValues: ['collateral_id' => $collateral->id, 'amount' => $amount],
                description: sprintf(
                    'Collateral #%d securing loan %s revalued from ₱%s to ₱%s',
                    $collateral->id,
                    CollateralAttacher::reference($loan),
                    number_format($previousAmount, 2),
                    number_format($amount, 2),
                ),
                userId: $user?->id,
            );
        }
    }

    /**
     * Refuse a `borrower_id` change on a collateral that is attached to a loan.
     *
     * Without this, `update()` is a second route to the outcome
     * AttachCollateralRequest now closes: attach your own collateral to your own
     * loan, then hand it to another member with a PUT. The ownership rule on
     * attach only constrains the moment of attaching.
     *
     * The bar is ATTACHED AT ALL, matching destroy() rather than the attach
     * guard's narrower "attached to an ACTIVE loan", for two reasons:
     *
     *  - The `loan_collaterals` row is the historical record of what secured
     *    that loan, and survives on purpose after the loan closes. `borrower_id`
     *    lives on `collaterals`, not on the pivot, so moving it rewrites that
     *    history: a settled loan's collateral list would name somebody who never
     *    pledged anything to it, and every document rendered from it afterwards
     *    would name the wrong owner.
     *  - An active-only bar leaks straight back into the hole above. A
     *    `completed` loan's collateral could be reassigned to another member and
     *    attached to their loan — both steps individually sanctioned — and then
     *    voiding a payment on the first loan re-activates it, which is the very
     *    transition assertNoDoublePledge() had to be added for.
     *  - The pivot also carries `snapshot_value`: an appraisal an operator
     *    struck against a NAMED owner and signed off on. Moving the owner
     *    underneath it falsifies that figure's provenance as well as the
     *    pledge's, and neither is recoverable from the row afterwards.
     *
     * Only a CHANGE is refused. The collateral edit form PUTs the whole payload,
     * `borrower_id` included, on every save, so treating presence as a change
     * would make an attached collateral uneditable.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertBorrowerIsNotBeingReassignedWhilePledged(Collateral $collateral, array $validated): void
    {
        if (! array_key_exists('borrower_id', $validated)) {
            return;
        }

        if ((int) $validated['borrower_id'] === (int) $collateral->borrower_id) {
            return;
        }

        $attachedCount = $collateral->loans()->count();

        if ($attachedCount === 0) {
            return;
        }

        throw ValidationException::withMessages([
            'borrower_id' => "This collateral is attached to {$attachedCount} loan(s) and cannot be moved to another member. Detach it from all loans first.",
        ]);
    }

    #[OA\Delete(
        path: '/api/collaterals/{id}',
        summary: 'Delete collateral',
        description: 'Rejects deletion when the collateral is attached to one or more loans.',
        tags: ['Collaterals'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Collateral deleted'),
            new OA\Response(response: 422, description: 'Collateral attached to loans'),
        ],
    )]
    public function destroy(Collateral $collateral): JsonResponse
    {
        $this->authorize('collaterals:delete');

        $attachedCount = $collateral->loans()->count();
        if ($attachedCount > 0) {
            throw ValidationException::withMessages([
                'collateral' => "This collateral is attached to {$attachedCount} loan(s). Detach it from all loans before deleting.",
            ]);
        }

        $collateral->delete();

        return response()->json(['message' => 'Collateral deleted successfully.']);
    }

    #[OA\Get(
        path: '/api/loans/{loanId}/collaterals',
        summary: 'List collaterals attached to a loan',
        tags: ['Collaterals'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loanId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Attached collaterals with snapshot pivot and `active_loans` (which includes this loan when it is itself active)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Collateral')),
                ]),
            ),
            new OA\Response(response: 404, description: 'Loan not found'),
        ],
    )]
    public function loanIndex(Loan $loan): AnonymousResourceCollection
    {
        $this->authorize('collaterals:view');

        $collaterals = $loan->collaterals()->with(['collateralType', 'activeLoans'])->get();

        return CollateralResource::valuedCollection($collaterals, request()->user());
    }

    #[OA\Post(
        path: '/api/loans/{loanId}/collaterals',
        summary: 'Attach a collateral to a loan',
        description: 'Creates a row in `loan_collaterals` with the snapshot value at attach time, recorded as `collateral_attached` in the audit log against the loan. Only while the loan is in draft or for_review status. The collateral must belong to the loan\'s own borrower. Rejects re-attaching the same collateral, and rejects a collateral already pledged to another loan in an active status.',
        tags: ['Collaterals'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loanId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['collateral_id', 'snapshot_value'],
                properties: [
                    new OA\Property(property: 'collateral_id', type: 'integer', example: 1),
                    new OA\Property(property: 'snapshot_value', type: 'number', example: 250000),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Collateral attached',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/Collateral'),
                ]),
            ),
            new OA\Response(response: 409, description: 'Another change to this collateral was saved at the same time; nothing was written. Reload and try again'),
            new OA\Response(response: 422, description: 'Validation error, the loan is not in draft or for_review status (on `status`), collateral belongs to a different borrower, already attached, or already pledged to another active loan (the message names the conflicting loan(s))'),
        ],
    )]
    public function attach(AttachCollateralRequest $request, Loan $loan): JsonResponse
    {
        $validated = $request->validated();

        // Ownership has already been rejected once by AttachCollateralRequest,
        // which scopes `collateral_id` to this loan's borrower, so a request
        // naming somebody else's asset never gets far enough to lock a row.
        // CollateralAttacher re-asserts it under the row lock, because that
        // check ran before this transaction and the owner can move underneath it.
        //
        // The collateral lock is the transaction's first statement, so every
        // plain read after it is answered from a post-lock snapshot — the
        // property CollateralPledgeGuard's docblock spells out. The loan row is
        // locked next, and its status read from that locked row. CollateralAttacher
        // holds the guards; PUT /loans/{loan} runs the same ones for its list.
        $attached = CollateralWriteTransaction::run(function () use ($loan, $validated, $request): Collateral {
            $collateralId = (int) $validated['collateral_id'];
            $locked = CollateralAttacher::lock([$collateralId]);
            $lockedLoan = CollateralAttacher::lockEditableLoan($loan);

            CollateralAttacher::attachLocked($lockedLoan, $locked->get($collateralId), $validated['snapshot_value'], $request->user());

            // Read back while still holding the lock, so the body describes
            // exactly the state that is about to commit — and so a concurrent
            // detach cannot make this return null between write and render.
            return $lockedLoan->collaterals()
                ->with(['collateralType', 'activeLoans'])
                ->where('collaterals.id', $collateralId)
                ->firstOrFail();
        });

        return CollateralResource::valued($attached, $request->user())
            ->response()
            ->setStatusCode(201);
    }

    /*
     * ── On attach() above, and the gap it used to leave ──────────────────
     *
     * GAP CLOSED. The KNOWN GAP note that used to sit here described a hole that
     * no longer exists; it is kept in this shape because the shape of the bug is
     * worth remembering, not because it is still open.
     *
     * CollateralPledgeGuard::assertCollateralIsFree(), which attach() runs
     * through CollateralAttacher, guards WRITES into `loan_collaterals`. It
     * cannot see a loan TRANSITIONING
     * into an active status while holding collateral another active loan also
     * holds, because that writes only to `loans` and touches no pivot row at
     * all. Every such transition now calls
     * CollateralPledgeGuard::lockCollateralsOf() as the FIRST statement of its
     * transaction — the ordering is load-bearing, see that method — and
     * CollateralPledgeGuard::assertNoDoublePledge() where its own sequencing
     * requires. Three paths were named in the audit:
     *
     *  1. RepaymentService::voidRepayment() — voiding a payment on a `completed`
     *     loan sets it back to `ongoing`/`released`. This was the reachable one,
     *     and reachable without anybody doing anything unusual: the attach guard
     *     DELIBERATELY permits an attach whose only other holder is `completed`,
     *     so a sanctioned attach followed by a sanctioned void produced two
     *     active holders. GUARDED.
     *  2. LoanService::release() — releasing a loan that was attached while the
     *     other holder was inactive. GUARDED, but the call sits at the END of
     *     the release transaction rather than at the status write; see the
     *     comment there for why the restructure path forces that ordering.
     *  3. LoanService::closeRestructuredSource() — reported as a third gap, and
     *     it is NOT one. `$previousStatus` at that line is an audit-log
     *     `oldValues` field, not a status write; the write beside it moves the
     *     source loan from `released`/`ongoing` INTO `restructured`, which is
     *     outside Loan::ACTIVE_STATUSES. That transition FREES a collateral, it
     *     cannot create a second holder. Deliberately left unguarded.
     *
     * `active_loans` remains an ARRAY, and should stay one. It is what keeps the
     * answer honest if a fourth path is ever added and missed: a collateral that
     * ends up on two active loans is reported as being on two, not silently
     * collapsed to one or to a boolean.
     */

    #[OA\Delete(
        path: '/api/loans/{loanId}/collaterals/{id}',
        summary: 'Detach a collateral from a loan',
        description: 'Removes the `loan_collaterals` row, which frees the collateral for a later attach, and records it as `collateral_detached` in the audit log against the loan. Only while the loan is in draft or for_review status. A loan leaving an active status frees its collateral too, without any write here — `active_loans` and the attach guard both read live loan status.',
        tags: ['Collaterals'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loanId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Collateral id', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Collateral detached'),
            new OA\Response(response: 404, description: 'Loan or collateral not found'),
            new OA\Response(response: 409, description: 'Another change to this collateral was saved at the same time; nothing was written. Reload and try again'),
            new OA\Response(response: 422, description: 'The loan is not in draft or for_review status (on `status`), or the collateral is not attached to it (on `collateral`)'),
        ],
    )]
    public function detach(Loan $loan, Collateral $collateral): JsonResponse
    {
        $this->authorize('collaterals:update');
        $this->authorize('loans:update');

        // The same lock order as attach(): the collateral row, then the loan
        // row, whose status is read under that lock.
        CollateralWriteTransaction::run(function () use ($loan, $collateral): void {
            CollateralAttacher::lock([$collateral->id]);
            $lockedLoan = CollateralAttacher::lockEditableLoan($loan);

            CollateralAttacher::detachLocked($lockedLoan, $collateral->id, request()->user());
        });

        return response()->json(['message' => 'Collateral detached successfully.']);
    }
}
