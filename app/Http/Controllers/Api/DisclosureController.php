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
        description: 'Returns all financial terms, deductions, and amortization schedule for printing/display. `totals` carries `total_principal`, `total_interest`, `total_obligation`, `total_amortization` (the schedule\'s total amortization column), `total_deductions`, `net_proceeds`, `total_finance_charges` (total deductions plus total interest) and `unitemised_deductions` (total deductions less the itemised `deductions.items`, signed: negative when the items add up to more than the total, 0 when they agree), schedule totals in whole centavos.',
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
