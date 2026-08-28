<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Collateral\AttachCollateralRequest;
use App\Http\Requests\Collateral\StoreCollateralRequest;
use App\Http\Requests\Collateral\UpdateCollateralRequest;
use App\Http\Resources\CollateralResource;
use App\Models\Collateral;
use App\Models\Loan;
use App\Services\CollateralPledgeGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class CollateralController extends Controller
{
    #[OA\Get(
        path: '/api/collaterals',
        summary: 'List collaterals',
        description: 'Filterable by borrower_id and collateral_type_id (alias `type`). Each row carries `active_loans`: the loans in an active status currently holding it, so the client never has to fan out over the loan list to work out what is pledged.',
        tags: ['Collaterals'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrower_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'Collateral type id', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Collateral list'),
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

        return CollateralResource::collection($collaterals);
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
            new OA\Response(response: 201, description: 'Collateral created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreCollateralRequest $request): JsonResponse
    {
        $collateral = Collateral::create($request->validated());
        $collateral->load(['collateralType', 'activeLoans']);

        return (new CollateralResource($collateral))
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
            new OA\Response(response: 200, description: 'Collateral details'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Collateral $collateral): CollateralResource
    {
        $this->authorize('collaterals:view');

        $collateral->load(['collateralType', 'activeLoans']);

        return new CollateralResource($collateral);
    }

    #[OA\Put(
        path: '/api/collaterals/{id}',
        summary: 'Update collateral',
        tags: ['Collaterals'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 200, description: 'Collateral updated'),
            new OA\Response(response: 422, description: 'Validation error, or a `borrower_id` change on a collateral attached to a loan'),
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
        // The lock is the FIRST statement in the transaction so that the count
        // inside the guard is the transaction's first PLAIN read: under
        // REPEATABLE READ the consistent snapshot is fixed by that first plain
        // SELECT, so counting after locking means counting a post-lock world.
        //
        // destroy() has the same check-then-act shape and is deliberately left
        // alone: `loan_collaterals.collateral_id` is restrictOnDelete, so a lost
        // race there fails on the foreign key instead of orphaning a pledge.
        $collateral = DB::transaction(function () use ($collateral, $validated): Collateral {
            $locked = Collateral::whereKey($collateral->getKey())->lockForUpdate()->first();

            if (! $locked) {
                throw ValidationException::withMessages([
                    'collateral' => 'This collateral no longer exists.',
                ]);
            }

            $this->assertBorrowerIsNotBeingReassignedWhilePledged($locked, $validated);

            $locked->update($validated);

            return $locked;
        });

        $collateral->load(['collateralType', 'activeLoans']);

        return new CollateralResource($collateral);
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
            new OA\Response(response: 200, description: 'Attached collaterals with snapshot pivot and `active_loans` (which includes this loan when it is itself active)'),
            new OA\Response(response: 404, description: 'Loan not found'),
        ],
    )]
    public function loanIndex(Loan $loan): AnonymousResourceCollection
    {
        $this->authorize('collaterals:view');

        $collaterals = $loan->collaterals()->with(['collateralType', 'activeLoans'])->get();

        return CollateralResource::collection($collaterals);
    }

    #[OA\Post(
        path: '/api/loans/{loanId}/collaterals',
        summary: 'Attach a collateral to a loan',
        description: 'Creates a row in `loan_collaterals` with the snapshot value at attach time. The collateral must belong to the loan\'s own borrower. Rejects re-attaching the same collateral, and rejects a collateral already pledged to another loan in an active status.',
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
            new OA\Response(response: 201, description: 'Collateral attached'),
            new OA\Response(response: 422, description: 'Validation error, collateral belongs to a different borrower, already attached, or already pledged to another active loan (the message names the conflicting loan(s))'),
        ],
    )]
    public function attach(AttachCollateralRequest $request, Loan $loan): JsonResponse
    {
        $validated = $request->validated();

        // Ownership has already been rejected once by AttachCollateralRequest,
        // which scopes `collateral_id` to this loan's borrower, so a request
        // naming somebody else's asset never gets far enough to lock a row. It
        // is re-asserted below all the same, because that check ran before this
        // transaction and the owner can move underneath it.
        //
        // The guards run INSIDE the transaction, opening with a row lock on the
        // collateral — the same shape LoanService::restructure() uses on its
        // source loan. Each is a read-then-insert, and the unique index on
        // (loan_id, collateral_id) cannot cover the important one: two requests
        // pledging one collateral to two DIFFERENT loans write two distinct
        // rows, so nothing at the schema level stops them. Every contender for a
        // given collateral serializes on that collateral's row.
        //
        // The lock is also the transaction's first statement, so every plain
        // read below is answered from a post-lock snapshot rather than one taken
        // before the row was pinned — the property CollateralPledgeGuard's
        // docblock spells out.
        $attached = DB::transaction(function () use ($loan, $validated): Collateral {
            $collateral = Collateral::whereKey($validated['collateral_id'])->lockForUpdate()->first();

            if (! $collateral) {
                throw ValidationException::withMessages([
                    'collateral_id' => 'This collateral no longer exists.',
                ]);
            }

            // Ownership, re-asserted under the lock.
            //
            // AttachCollateralRequest already scoped `collateral_id` to this
            // loan's borrower, but that ran BEFORE this transaction opened, and
            // PUT /api/collaterals/{id} can move a collateral between members.
            // Validated at T0, reassigned at T1, attached at T2 pledges to this
            // loan a collateral now registered to somebody else. The row above
            // was read FOR UPDATE, which always returns the latest committed
            // version rather than the snapshot, so this comparison sees the
            // reassignment; update() takes the same lock, so the two serialize
            // whichever way they interleave.
            //
            // `$loan->borrower_id` needs no such care: UpdateLoanRequest exposes
            // no `borrower_id`, so a loan's member is fixed at creation.
            //
            // Same message as the form request's, deliberately — see its
            // messages() for why the two failure modes are not told apart.
            if ((int) $collateral->borrower_id !== (int) $loan->borrower_id) {
                throw ValidationException::withMessages([
                    'collateral_id' => 'This collateral is not registered to this loan\'s borrower.',
                ]);
            }

            if ($loan->collaterals()->where('collaterals.id', $collateral->id)->exists()) {
                throw ValidationException::withMessages([
                    'collateral_id' => 'This collateral is already attached to the loan.',
                ]);
            }

            CollateralPledgeGuard::assertCollateralIsFree($collateral, $loan);

            $loan->collaterals()->attach($collateral->id, [
                'snapshot_value' => $validated['snapshot_value'],
                'attached_at' => now(),
            ]);

            // Read back while still holding the lock, so the body describes
            // exactly the state that is about to commit — and so a concurrent
            // detach cannot make this return null between write and render.
            return $loan->collaterals()
                ->with(['collateralType', 'activeLoans'])
                ->where('collaterals.id', $collateral->id)
                ->firstOrFail();
        });

        return (new CollateralResource($attached))
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
     * CollateralPledgeGuard::assertCollateralIsFree(), which attach() calls,
     * guards WRITES into `loan_collaterals`. It cannot see a loan TRANSITIONING
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
        description: 'Removes the `loan_collaterals` row, which frees the collateral for a later attach. A loan leaving an active status frees it too, without any write here — `active_loans` and the attach guard both read live loan status.',
        tags: ['Collaterals'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loanId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Collateral id', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Collateral detached'),
            new OA\Response(response: 404, description: 'Not attached'),
        ],
    )]
    public function detach(Loan $loan, Collateral $collateral): JsonResponse
    {
        $this->authorize('collaterals:update');
        $this->authorize('loans:update');

        $detached = $loan->collaterals()->detach($collateral->id);

        if ($detached === 0) {
            throw ValidationException::withMessages([
                'collateral' => 'This collateral is not attached to the loan.',
            ]);
        }

        return response()->json(['message' => 'Collateral detached successfully.']);
    }
}
