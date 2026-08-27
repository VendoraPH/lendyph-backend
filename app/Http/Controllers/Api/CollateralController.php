<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Collateral\AttachCollateralRequest;
use App\Http\Requests\Collateral\StoreCollateralRequest;
use App\Http\Requests\Collateral\UpdateCollateralRequest;
use App\Http\Resources\CollateralResource;
use App\Models\Collateral;
use App\Models\Loan;
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

        $typeId = request('collateral_type_id', request('type'));

        $collaterals = Collateral::with(['collateralType', 'activeLoans'])
            ->when(request('borrower_id'), fn ($q, $borrowerId) => $q->where('borrower_id', $borrowerId))
            ->when($typeId, fn ($q, $tid) => $q->where('collateral_type_id', $tid))
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
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateCollateralRequest $request, Collateral $collateral): CollateralResource
    {
        $collateral->update($request->validated());
        $collateral->load(['collateralType', 'activeLoans']);

        return new CollateralResource($collateral);
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
        description: 'Creates a row in `loan_collaterals` with the snapshot value at attach time. Rejects re-attaching the same collateral, and rejects a collateral already pledged to another loan in an active status.',
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
            new OA\Response(response: 422, description: 'Validation error, already attached, or already pledged to another active loan (the message names the conflicting loan(s))'),
        ],
    )]
    public function attach(AttachCollateralRequest $request, Loan $loan): JsonResponse
    {
        $validated = $request->validated();

        // The guards run INSIDE the transaction, opening with a row lock on the
        // collateral — the same shape LoanService::restructure() uses on its
        // source loan. Both checks below are exists()-then-insert, and the
        // unique index on (loan_id, collateral_id) cannot cover the important
        // one: two requests pledging one collateral to two DIFFERENT loans write
        // two distinct rows, so nothing at the schema level stops them. Every
        // contender for a given collateral serializes on that collateral's row.
        $attached = DB::transaction(function () use ($loan, $validated): Collateral {
            $collateral = Collateral::whereKey($validated['collateral_id'])->lockForUpdate()->first();

            if (! $collateral) {
                throw ValidationException::withMessages([
                    'collateral_id' => 'This collateral no longer exists.',
                ]);
            }

            if ($loan->collaterals()->where('collaterals.id', $collateral->id)->exists()) {
                throw ValidationException::withMessages([
                    'collateral_id' => 'This collateral is already attached to the loan.',
                ]);
            }

            $this->assertNotPledgedToAnotherActiveLoan($collateral, $loan);

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

    /**
     * Refuse a collateral that some other loan in Loan::ACTIVE_STATUSES already holds.
     *
     * Note which side the status test is on: it is the CURRENT holders that must
     * not be active, not the loan being attached to. Pledging to a draft loan a
     * collateral that a released loan is holding is exactly the double pledge
     * this rejects.
     *
     * Call only from inside the transaction that holds the collateral row lock —
     * on its own this is a read that a concurrent attach can race.
     *
     * KNOWN GAP, and the reason this is not the whole story: this guards WRITES
     * into `loan_collaterals`. It does not guard a loan TRANSITIONING into an
     * active status while holding collateral another active loan also holds, and
     * two sanctioned paths do exactly that:
     *
     *  - RepaymentService::void() — voiding a payment on a `completed` loan sets
     *    it back to `ongoing`/`released`. This one is reachable without anybody
     *    doing anything unusual, because the check below DELIBERATELY permits an
     *    attach whose only other holder is `completed`: attach to the second
     *    loan is sanctioned, then the void re-activates the first.
     *  - LoanService::release() — releasing a draft that was attached while the
     *    other holder was inactive.
     *
     * The durable fix for both is a check on the status transition, not on the
     * pivot write, so it does not belong here. Until it lands, `active_loans`
     * being an ARRAY is what keeps the answer honest: a collateral that ends up
     * on two active loans is reported as being on two, not silently collapsed to
     * one or to a boolean.
     *
     * @throws ValidationException naming the conflicting loan(s)
     */
    private function assertNotPledgedToAnotherActiveLoan(Collateral $collateral, Loan $loan): void
    {
        $conflicts = $collateral->activeLoans()
            ->whereKeyNot($loan->getKey())
            ->get();

        if ($conflicts->isEmpty()) {
            return;
        }

        $references = $conflicts
            ->map(fn (Loan $active) => $active->loan_account_number ?? "loan #{$active->getKey()}")
            ->implode(', ');

        throw ValidationException::withMessages([
            'collateral_id' => $conflicts->count() === 1
                ? "This collateral is already pledged to active loan {$references}. Detach it there first."
                : "This collateral is already pledged to active loans {$references}. Detach it there first.",
        ]);
    }

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
