<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\ContactVisibility;
use App\Services\PromissoryNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PromissoryNoteController extends Controller
{
    public function __construct(private PromissoryNoteService $promissoryNoteService) {}

    #[OA\Get(
        path: '/api/loans/{loan}/promissory-note',
        summary: 'Generate promissory note',
        description: 'Returns borrower promise-to-pay data, co-maker details, loan terms, and signature fields. `borrower.address` and each `co_makers[].address` are null for a caller without `borrowers:view` or who cannot see the member\'s branch.',
        tags: ['Loan Documents'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Promissory note data',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'loan_terms', type: 'object', description: 'The loan\'s terms. Only the late penalty fields are documented here.', properties: [
                            new OA\Property(property: 'penalty_rate', type: 'number', description: 'The late penalty rate per monthly charge, a percent of the period\'s unpaid principal; 0 on a loan created under a penalty configuration, which charges by `penalty_brackets`.'),
                            new OA\Property(property: 'penalty_brackets', type: 'array', nullable: true, description: 'The brackets the loan was created with: days past the due date (the day after it is day 1), `days_to` null for open-ended, the rate in percent, never rounded. Null on a loan charged by `penalty_rate`.', items: new OA\Items(properties: [
                                new OA\Property(property: 'days_from', type: 'integer', example: 1),
                                new OA\Property(property: 'days_to', type: 'integer', nullable: true, example: 5),
                                new OA\Property(property: 'rate', type: 'number', example: 20),
                            ], type: 'object')),
                            new OA\Property(property: 'penalty_terms', type: 'string', example: '1–5 days late: 20%; 6–10 days late: 30%; 11 days or more late: 50%', description: 'The late penalty in words: the brackets, the rate ("3% a month"), or "No late penalty".'),
                            new OA\Property(property: 'grace_period_days', type: 'integer'),
                        ]),
                    ]),
                ]),
            ),
            new OA\Response(response: 422, description: 'Loan not eligible'),
            new OA\Response(response: 404, description: 'Loan not found'),
        ],
    )]
    public function show(Request $request, Loan $loan): JsonResponse
    {
        $this->authorize('loans:view');

        if (! in_array($loan->status, ['approved', 'released', 'ongoing', 'completed'])) {
            return response()->json([
                'message' => 'Promissory note is only available for approved, released, ongoing, or completed loans.',
            ], 422);
        }

        return response()->json([
            'data' => $this->promissoryNoteService->generatePromissoryNote(
                $loan,
                app(ContactVisibility::class)->allows($request->user(), $loan->borrower?->branch_id),
            ),
        ]);
    }
}
