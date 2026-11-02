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
        description: <<<'DESC'
Returns all financial terms, deductions, and amortization schedule for printing/display, with the R.A. 3765 (Truth in Lending) figures worked out by the server in whole centavos.

`totals` carries `total_principal`, `total_interest`, `total_obligation`, `total_amortization` (the schedule's total amortization column), `total_deductions`, `net_proceeds`, `total_finance_charges` (interest plus the cooperative's own fees only), `total_non_finance_charges` and `unitemised_deductions` (total deductions less the itemised `deductions.items`, signed: negative when the items add up to more than the total, 0 when they agree).

Each deduction item is sorted by its type, as the release journal books it:
- `finance_charges`: `interest`, `items` (`{name, amount}`: processing and service fees, catalog fees and any other untyped deduction, then the unitemised remainder as "Other Charges" when positive or "Adjustment" when negative) and `total`.
- `non_finance_charges`: `items` (`{name, amount, deducted, owed}`: notarial fees, taxes (a name containing "tax" or "documentary stamp", or "DST"), and one "Insurance Premium" line whose `amount` is the premium charged, `deducted` what was collected at release and `owed` what the release left unpaid; on a restructure that carried premium over, an "Insurance premium carried from the previous loan" line, all owed, none deducted, with `provisional`: true before release, when it is the open source loan's live premium owed, false after) and `total` (of `amount`).
- `proceeds`: `principal`, `finance_charges_deducted`, `non_finance_charges_deducted`, `share_capital` (the member's own equity, never a charge) and `net_proceeds`; principal less the three deducted parts is the net proceeds to the centavo.
- `rates`: `contractual_rate` and `rate_frequency` as quoted, `nominal_annual_rate` (the rate times the periods in a year of its quoted period; null when that period is unknown), and `effective_rate_monthly` / `effective_rate_annual` per BSP Circular 730: the rate per period that discounts each instalment's total due to the amount financed (principal less the finance charges deducted), times the payment frequency's periods in a year (a loan paid at maturity: 12 / its months or 365 / its days), monthly being the annual over 12. Percent to 4 places; null when it cannot be solved.
DESC,
        tags: ['Loan Documents'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Disclosure statement data',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'totals', type: 'object', properties: [
                            new OA\Property(property: 'total_finance_charges', type: 'number', format: 'float'),
                            new OA\Property(property: 'total_non_finance_charges', type: 'number', format: 'float'),
                            new OA\Property(property: 'unitemised_deductions', type: 'number', format: 'float'),
                        ]),
                        new OA\Property(property: 'finance_charges', type: 'object', properties: [
                            new OA\Property(property: 'interest', type: 'number', format: 'float'),
                            new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'amount', type: 'number', format: 'float'),
                            ])),
                            new OA\Property(property: 'total', type: 'number', format: 'float'),
                        ]),
                        new OA\Property(property: 'non_finance_charges', type: 'object', properties: [
                            new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'amount', type: 'number', format: 'float'),
                                new OA\Property(property: 'deducted', type: 'number', format: 'float'),
                                new OA\Property(property: 'owed', type: 'number', format: 'float'),
                            ])),
                            new OA\Property(property: 'total', type: 'number', format: 'float'),
                        ]),
                        new OA\Property(property: 'proceeds', type: 'object', properties: [
                            new OA\Property(property: 'principal', type: 'number', format: 'float'),
                            new OA\Property(property: 'finance_charges_deducted', type: 'number', format: 'float'),
                            new OA\Property(property: 'non_finance_charges_deducted', type: 'number', format: 'float'),
                            new OA\Property(property: 'share_capital', type: 'number', format: 'float'),
                            new OA\Property(property: 'net_proceeds', type: 'number', format: 'float'),
                        ]),
                        new OA\Property(property: 'rates', type: 'object', properties: [
                            new OA\Property(property: 'contractual_rate', type: 'number', format: 'float'),
                            new OA\Property(property: 'rate_frequency', type: 'string', nullable: true),
                            new OA\Property(property: 'nominal_annual_rate', type: 'number', format: 'float', nullable: true),
                            new OA\Property(property: 'effective_rate_monthly', type: 'number', format: 'float', nullable: true),
                            new OA\Property(property: 'effective_rate_annual', type: 'number', format: 'float', nullable: true),
                        ]),
                    ]),
                ]),
            ),
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
