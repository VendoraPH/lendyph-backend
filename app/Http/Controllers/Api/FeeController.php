<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fee\CheckFeeFormulaRequest;
use App\Http\Requests\Fee\StoreFeeRequest;
use App\Http\Requests\Fee\UpdateFeeRequest;
use App\Http\Resources\FeeResource;
use App\Models\Fee;
use App\Services\FeeAmount;
use App\Services\Fees\FeeExpressionException;
use App\Services\Fees\FeeFormulaInputs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class FeeController extends Controller
{
    private const EXPRESSION_DESCRIPTION = 'Formula only: the formula written out, with numbers, + − × ÷ (or * /), brackets, a postfix % (÷ 100) and the variables Principal Amount, Loan Amount, Interest Rate, Loan Term and Term in Days. When set, value is stored as 0 and per_month as false; min_amount and max_amount still clamp. Cleared on any other type, or when empty.';

    #[OA\Post(
        path: '/api/fees/formula-check',
        summary: 'Check a written fee formula on a sample loan',
        description: 'Writes nothing and writes no audit row. The sample defaults to ₱100,000.00 at 3 monthly for 12 months, starting today; its maturity date is worked out as the schedule does. Needs fees:create or fees:update.',
        tags: ['Fees'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['formula_expression'],
                properties: [
                    new OA\Property(property: 'formula_expression', type: 'string', example: '(Principal Amount * 2.5%) / 365'),
                    new OA\Property(property: 'principal_amount', type: 'string', nullable: true, example: '100000.00'),
                    new OA\Property(property: 'interest_rate', type: 'string', nullable: true, example: '3'),
                    new OA\Property(property: 'interest_rate_frequency', type: 'string', nullable: true, enum: ['daily', 'weekly', 'bi_weekly', 'semi_monthly', 'monthly', 'annual']),
                    new OA\Property(property: 'term', type: 'integer', nullable: true, example: 12),
                    new OA\Property(property: 'term_unit', type: 'string', nullable: true, enum: ['months', 'days']),
                    new OA\Property(property: 'min_amount', type: 'string', nullable: true),
                    new OA\Property(property: 'max_amount', type: 'string', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'The formula worked out on the sample loan', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'valid', type: 'boolean', example: true),
                    new OA\Property(property: 'expression', type: 'string'),
                    new OA\Property(property: 'variables', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'inputs', ref: '#/components/schemas/FeeFormulaInputs'),
                    new OA\Property(property: 'raw_amount', type: 'string', example: '6.849315', description: 'The exact result to 6 decimals, half up'),
                    new OA\Property(property: 'rounded_amount', type: 'string', example: '6.85', description: 'Half up to the centavo, before the bounds'),
                    new OA\Property(property: 'amount', type: 'string', example: '6.85', description: 'After min_amount and max_amount'),
                    new OA\Property(property: 'clamped', type: 'string', nullable: true, enum: ['min', 'max']),
                ]),
            ])),
            new OA\Response(response: 403, description: 'Missing fees:create and fees:update'),
            new OA\Response(response: 422, description: 'A refused formula (`formula_expression`, in words), or the sample loan divides by zero or goes below ₱0.00 (`sample`)'),
        ],
    )]
    public function formulaCheck(CheckFeeFormulaRequest $request): JsonResponse
    {
        $expression = $request->expression();
        $inputs = FeeFormulaInputs::fromLoan($request->sampleLoan());

        try {
            $result = FeeAmount::forExpression($expression, $inputs, $request->validated('min_amount'), $request->validated('max_amount'));
        } catch (FeeExpressionException $e) {
            throw ValidationException::withMessages(['sample' => [$e->messageForSample()]]);
        }

        if ($result === null) {
            throw ValidationException::withMessages(['sample' => ['The sample loan is missing a figure the formula uses.']]);
        }

        return response()->json(['data' => [
            'valid' => true,
            'expression' => $expression->source(),
            'variables' => $expression->variables(),
            'inputs' => $inputs->snapshot(),
            'raw_amount' => $result->rawAmount,
            'rounded_amount' => $result->roundedAmount,
            'amount' => $result->amount,
            'clamped' => $result->clamped,
        ]]);
    }

    #[OA\Get(
        path: '/api/fees',
        summary: 'List fees',
        tags: ['Fees'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Fee list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Fee')),
            ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('fees:view');

        return FeeResource::collection(Fee::orderBy('name')->get());
    }

    #[OA\Post(
        path: '/api/fees',
        summary: 'Create fee',
        tags: ['Fees'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'type', 'value'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Processing Fee'),
                    new OA\Property(property: 'type', type: 'string', enum: ['fixed', 'percentage', 'formula']),
                    new OA\Property(property: 'value', type: 'number', example: 500, description: 'Pesos for a fixed fee; the rate (percent of principal, at most 100 for a formula) for a percentage or formula fee.'),
                    new OA\Property(property: 'per_month', type: 'boolean', description: 'Formula only: multiply by the term in months (a days term over 30).'),
                    new OA\Property(property: 'min_amount', type: 'number', nullable: true, example: 150, description: 'Formula only: the least it charges, in pesos.'),
                    new OA\Property(property: 'max_amount', type: 'number', nullable: true, example: 1500, description: 'Formula only: the most it charges, in pesos; not below min_amount.'),
                    new OA\Property(property: 'formula_expression', type: 'string', nullable: true, maxLength: 255, example: '(Principal Amount × 2.5%) ÷ 365', description: self::EXPRESSION_DESCRIPTION),
                    new OA\Property(property: 'applicable_product_ids', type: 'array', items: new OA\Items(type: 'integer')),
                    new OA\Property(property: 'conditions', type: 'object'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Fee created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Fee')])),
            new OA\Response(response: 422, description: 'Validation error; a refused formula is on `formula_expression`, in words'),
        ],
    )]
    public function store(StoreFeeRequest $request): JsonResponse
    {
        $fee = Fee::create($request->validated());

        return (new FeeResource($fee))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/fees/{id}',
        summary: 'Show fee',
        tags: ['Fees'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Fee details'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Fee $fee): FeeResource
    {
        $this->authorize('fees:view');

        return new FeeResource($fee);
    }

    #[OA\Put(
        path: '/api/fees/{id}',
        summary: 'Update fee',
        tags: ['Fees'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'type', type: 'string', enum: ['fixed', 'percentage', 'formula']),
            new OA\Property(property: 'value', type: 'number'),
            new OA\Property(property: 'per_month', type: 'boolean'),
            new OA\Property(property: 'min_amount', type: 'number', nullable: true),
            new OA\Property(property: 'max_amount', type: 'number', nullable: true),
            new OA\Property(property: 'formula_expression', type: 'string', nullable: true, maxLength: 255, description: self::EXPRESSION_DESCRIPTION),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Fee updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Fee')])),
            new OA\Response(response: 422, description: 'Validation error; a refused formula is on `formula_expression`, in words. Clearing a written formula without sending a rate is refused on `value`.'),
        ],
    )]
    public function update(UpdateFeeRequest $request, Fee $fee): FeeResource
    {
        $fee->update($request->validated());

        return new FeeResource($fee);
    }

    #[OA\Delete(
        path: '/api/fees/{id}',
        summary: 'Delete fee',
        tags: ['Fees'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Fee deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function destroy(Fee $fee): JsonResponse
    {
        $this->authorize('fees:delete');

        $fee->delete();

        return response()->json(['message' => 'Fee deleted successfully.']);
    }
}
