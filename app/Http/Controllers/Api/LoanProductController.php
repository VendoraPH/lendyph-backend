<?php

namespace App\Http\Controllers\Api;

use App\Enums\LoanFrequency;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoanProduct\StoreLoanProductRequest;
use App\Http\Requests\LoanProduct\UpdateLoanProductRequest;
use App\Http\Resources\LoanProductResource;
use App\Models\LoanProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class LoanProductController extends Controller
{
    #[OA\Get(
        path: '/api/loan-products',
        summary: 'List loan products',
        tags: ['Loan Products'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'inactive'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loan product list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('loans:view');

        $products = LoanProduct::query()
            ->when(request('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when(request('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('name')
            ->get();

        return LoanProductResource::collection($products);
    }

    #[OA\Post(
        path: '/api/loan-products',
        summary: 'Create loan product',
        tags: ['Loan Products'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'interest_rate', 'interest_method', 'term', 'frequency'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Personal Loan - 12 Months'),
                    new OA\Property(property: 'interest_rate', type: 'number', example: 3.0),
                    new OA\Property(property: 'interest_method', type: 'string', enum: ['straight', 'diminishing', 'upon_maturity']),
                    new OA\Property(property: 'term', type: 'integer', example: 12),
                    new OA\Property(property: 'frequency', type: 'string', enum: LoanFrequency::class),
                    new OA\Property(property: 'frequencies', type: 'array', items: new OA\Items(type: 'string', enum: LoanFrequency::class)),
                    new OA\Property(property: 'processing_fee', type: 'number', example: 2.0),
                    new OA\Property(property: 'service_fee', type: 'number', example: 1.0),
                    new OA\Property(property: 'penalty_rate', type: 'number', example: 3.0),
                    new OA\Property(property: 'grace_period_days', type: 'integer', example: 3),
                    new OA\Property(property: 'min_amount', type: 'number', example: 5000),
                    new OA\Property(property: 'max_amount', type: 'number', example: 500000),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Loan product created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreLoanProductRequest $request): JsonResponse
    {
        $product = LoanProduct::create($request->validated());

        return (new LoanProductResource($product))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/loan-products/{id}',
        summary: 'Show loan product',
        tags: ['Loan Products'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loan product details'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(LoanProduct $loanProduct): LoanProductResource
    {
        $this->authorize('loans:view');

        return new LoanProductResource($loanProduct);
    }

    #[OA\Put(
        path: '/api/loan-products/{id}',
        summary: 'Update loan product',
        tags: ['Loan Products'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 200, description: 'Loan product updated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateLoanProductRequest $request, LoanProduct $loanProduct): LoanProductResource
    {
        $loanProduct->update($request->validated());

        return new LoanProductResource($loanProduct);
    }

    #[OA\Delete(
        path: '/api/loan-products/{id}',
        summary: 'Delete loan product',
        tags: ['Loan Products'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Loan product deleted'),
            new OA\Response(response: 409, description: 'Cannot delete — loans reference this product'),
        ],
    )]
    public function destroy(LoanProduct $loanProduct): JsonResponse
    {
        $this->authorize('loans:create');

        if ($loanProduct->loans()->exists()) {
            return response()->json(['message' => 'Cannot delete a loan product with existing loans.'], 409);
        }

        $loanProduct->delete();

        return response()->json(['message' => 'Loan product deleted successfully.']);
    }
}
