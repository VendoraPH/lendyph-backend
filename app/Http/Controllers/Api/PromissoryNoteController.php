<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\LoanDocumentPrint;
use App\Services\ContactVisibility;
use App\Services\LoanDocumentTerms;
use App\Services\PromissoryNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PromissoryNoteController extends Controller
{
    public function __construct(private PromissoryNoteService $promissoryNoteService, private LoanDocumentTerms $terms) {}

    #[OA\Get(
        path: '/api/loans/{loan}/promissory-note',
        summary: 'Generate promissory note',
        description: 'Returns borrower promise-to-pay data, co-maker details, loan terms, and signature fields. `borrower.address` and each `co_makers[].address` are null for a caller without `borrowers:view` or who cannot see the member\'s branch. `terms_fingerprint` and `terms_version` are the terms this note states (a versioned fingerprint of its amounts, rate, term and frequency, dates, instalments and penalty clause; never names, codes or addresses), worked out from this very payload by the rule the release check uses: send both with POST /api/loans/{loan}/print-log when it is printed. `terms_fingerprint` is null when the terms can\'t be worked out.',
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
                        new OA\Property(property: 'terms_fingerprint', type: 'string', nullable: true, pattern: '^[0-9a-f]{64}$', description: 'The terms this note states, for POST /api/loans/{loan}/print-log'),
                        new OA\Property(property: 'terms_version', type: 'integer', example: 1, description: 'The version of the terms rule `terms_fingerprint` was worked out by'),
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

        $note = $this->promissoryNoteService->generatePromissoryNote(
            $loan,
            app(ContactVisibility::class)->allows($request->user(), $loan->borrower?->branch_id),
        );

        // The terms this very payload states, so a print records what its
        // paper shows (POST /loans/{loan}/print-log).
        return response()->json([
            'data' => $note + [
                'terms_fingerprint' => $this->terms->fingerprintOf(LoanDocumentPrint::PROMISSORY_NOTE, $note),
                'terms_version' => LoanDocumentTerms::VERSION,
            ],
        ]);
    }
}
