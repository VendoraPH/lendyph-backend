<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\PromissoryNoteService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PromissoryNoteController extends Controller
{
    public function __construct(private PromissoryNoteService $promissoryNoteService) {}

    #[OA\Get(
        path: '/api/loans/{loan}/promissory-note',
        summary: 'Generate promissory note',
        description: 'Returns borrower promise-to-pay data, co-maker details, loan terms, and signature fields',
        tags: ['Loan Documents'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Promissory note data'),
            new OA\Response(response: 422, description: 'Loan not eligible'),
            new OA\Response(response: 404, description: 'Loan not found'),
        ],
    )]
    public function show(Loan $loan): JsonResponse
    {
        $this->authorize('loans:view');

        if (! in_array($loan->status, ['approved', 'released', 'closed'])) {
            return response()->json([
                'message' => 'Promissory note is only available for approved, released, or closed loans.',
            ], 422);
        }

        return response()->json([
            'data' => $this->promissoryNoteService->generatePromissoryNote($loan),
        ]);
    }
}
