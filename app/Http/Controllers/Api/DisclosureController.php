<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\DisclosureService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class DisclosureController extends Controller
{
    public function __construct(private DisclosureService $disclosureService) {}

    #[OA\Get(
        path: '/api/loans/{loan}/disclosure',
        summary: 'Generate disclosure statement',
        description: 'Returns all financial terms, deductions, and amortization schedule for printing/display',
        tags: ['Loan Documents'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Disclosure statement data'),
            new OA\Response(response: 422, description: 'Loan not eligible for disclosure'),
            new OA\Response(response: 404, description: 'Loan not found'),
        ],
    )]
    public function show(Loan $loan): JsonResponse
    {
        $this->authorize('loans:view');

        if (! in_array($loan->status, ['approved', 'released', 'ongoing', 'completed'])) {
            return response()->json([
                'message' => 'Disclosure is only available for approved, released, ongoing, or completed loans.',
            ], 422);
        }

        return response()->json([
            'data' => $this->disclosureService->generateDisclosure($loan),
        ]);
    }
}
