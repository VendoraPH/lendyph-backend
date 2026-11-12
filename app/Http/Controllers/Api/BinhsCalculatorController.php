<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Loan\BinhsCalculatorRequest;
use App\Models\Loan;
use App\Services\Binhs\BinhsCalculator;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class BinhsCalculatorController extends Controller
{
    #[OA\Post(
        path: '/api/loans/amortization-calculator/binhs',
        summary: 'Amortization (BINHS) schedules',
        description: 'The Ideal, Worst Case (nothing paid) and Custom (the periods in `paid_flags` paid) schedules, worked out in exact maths: monthly rate = annual ÷ 12 ÷ 100, a level payment, interest on the unrounded balance, a 20% penalty on principal carried unpaid, the first due date on `start_date`, and each figure rounded half up to the centavo. Money is a 2-decimal string. With `loan_id`, `prefill` holds that loan\'s principal, its rate a year (exact; null when not a finite decimal), its term in months (null for a days term) and start date; `loan_id` alone answers the prefill only (`scenarios` null). Writes nothing.',
        tags: ['Amortization Schedule'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'principal', type: 'number', minimum: 1, maximum: 100000000, example: 10000, description: 'Required unless `loan_id` is sent alone'),
                    new OA\Property(property: 'annual_rate', type: 'number', minimum: 0, maximum: 100, example: 24, description: 'Percent a year, up to 4 decimals'),
                    new OA\Property(property: 'term_months', type: 'integer', minimum: 1, maximum: 60, example: 6),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date', description: 'The first due date'),
                    new OA\Property(property: 'scbu_per_period', type: 'number', minimum: 0, nullable: true, example: 100),
                    new OA\Property(property: 'paid_flags', type: 'array', maxItems: 60, nullable: true, items: new OA\Items(type: 'boolean'), description: 'Custom: whether each period, from period 1, is paid; a missing one is unpaid; at most `term_months`'),
                    new OA\Property(property: 'loan_id', type: 'integer', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: '`prefill` (or null) and `scenarios` (`ideal`, `worst_case`, `custom`, each with `rows` and `totals`), or null'),
            new OA\Response(response: 403, description: 'Missing loans:view'),
            new OA\Response(response: 404, description: 'No such loan, or another branch\'s'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function __invoke(BinhsCalculatorRequest $request, BinhsCalculator $calculator): JsonResponse
    {
        $prefill = null;

        if ($request->filled('loan_id')) {
            $loan = Loan::query()->visibleTo($request->user())->whereKey((int) $request->validated('loan_id'))->first()
                ?? abort(404, 'Loan not found.');
            $prefill = $calculator->prefillFor($loan);
        }

        $scenarios = null;

        if ($request->wantsSchedules()) {
            $input = $request->scheduleInputs();
            $scenarios = $calculator->calculate(
                $input['principal'],
                $input['annual_rate'],
                $input['term_months'],
                $input['scbu_per_period'],
                $input['start_date'],
                $input['paid_flags'],
            );
        }

        return response()->json(['data' => ['prefill' => $prefill, 'scenarios' => $scenarios]]);
    }
}
