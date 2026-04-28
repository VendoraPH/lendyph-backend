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
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class CollateralController extends Controller
{
    #[OA\Get(
        path: '/api/collaterals',
        summary: 'List collaterals',
        description: 'Filterable by borrower_id and collateral_type_id (alias `type`).',
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

        $collaterals = Collateral::with('collateralType')
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
        $collateral->load('collateralType');

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

        $collateral->load('collateralType');

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
        $collateral->load('collateralType');

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
            new OA\Response(response: 200, description: 'Attached collaterals with snapshot pivot'),
            new OA\Response(response: 404, description: 'Loan not found'),
        ],
    )]
    public function loanIndex(Loan $loan): AnonymousResourceCollection
    {
        $this->authorize('collaterals:view');

        $collaterals = $loan->collaterals()->with('collateralType')->get();

        return CollateralResource::collection($collaterals);
    }

    #[OA\Post(
        path: '/api/loans/{loanId}/collaterals',
        summary: 'Attach a collateral to a loan',
        description: 'Creates a row in `loan_collaterals` with the snapshot value at attach time. Rejects re-attaching the same collateral.',
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
            new OA\Response(response: 422, description: 'Validation error or already attached'),
        ],
    )]
    public function attach(AttachCollateralRequest $request, Loan $loan): JsonResponse
    {
        $validated = $request->validated();

        if ($loan->collaterals()->where('collaterals.id', $validated['collateral_id'])->exists()) {
            throw ValidationException::withMessages([
                'collateral_id' => 'This collateral is already attached to the loan.',
            ]);
        }

        $loan->collaterals()->attach($validated['collateral_id'], [
            'snapshot_value' => $validated['snapshot_value'],
            'attached_at' => now(),
        ]);

        $collateral = $loan->collaterals()
            ->with('collateralType')
            ->where('collaterals.id', $validated['collateral_id'])
            ->first();

        return (new CollateralResource($collateral))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Delete(
        path: '/api/loans/{loanId}/collaterals/{id}',
        summary: 'Detach a collateral from a loan',
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
