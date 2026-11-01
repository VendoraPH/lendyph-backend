<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Loan\AmortizationCalculatorRequest;
use App\Services\AmortizationCalculatorService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class AmortizationCalculatorController extends Controller
{
    #[OA\Post(
        path: '/api/loans/amortization-calculator',
        summary: 'Amortization Calculator schedule',
        description: 'A what-if schedule with no product and no loan, built by the engine a release writes the real schedule with. `rows` and `totals` have the shape of POST /loans/preview `amortization` and equal it for a product with the same settings. With `compare: true`, `comparison` holds the schedule under each interest method. Writes nothing.',
        tags: ['Amortization Schedule'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['principal_amount', 'interest_rate', 'interest_rate_frequency', 'interest_method', 'term', 'term_unit', 'frequency', 'start_date'],
                properties: [
                    new OA\Property(property: 'principal_amount', type: 'number', example: 50000),
                    new OA\Property(property: 'interest_rate', type: 'number', example: 3),
                    new OA\Property(property: 'interest_rate_frequency', type: 'string', enum: ['daily', 'weekly', 'bi_weekly', 'semi_monthly', 'monthly']),
                    new OA\Property(property: 'interest_method', type: 'string', enum: ['straight', 'diminishing', 'upon_maturity']),
                    new OA\Property(property: 'term', type: 'integer', example: 6),
                    new OA\Property(property: 'term_unit', type: 'string', enum: ['months', 'days']),
                    new OA\Property(property: 'frequency', type: 'string', enum: ['daily', 'weekly', 'bi_weekly', 'semi_monthly', 'monthly', 'upon_maturity']),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'scb_amount', type: 'number', nullable: true),
                    new OA\Property(property: 'compare', type: 'boolean', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'The schedule, its totals and, with `compare`, every method'),
            new OA\Response(response: 403, description: 'Missing loans:view'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function __invoke(AmortizationCalculatorRequest $request, AmortizationCalculatorService $calculator): JsonResponse
    {
        return response()->json(['data' => $calculator->calculate($request->validated())]);
    }
}
