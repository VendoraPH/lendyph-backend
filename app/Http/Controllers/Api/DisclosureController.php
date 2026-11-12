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

Before release (`released_at` null, and only for an open application: draft, for review or approved; a rejected or void loan gets none), the catalog fees the release will charge (the same fee rules GET /api/loans/{loan}/release-preview applies) are added after the stored deductions, each marked `provisional`: true with `provisional_reason`: "release_fee", in `deductions.items` and on its finance or non-finance line. A catalog fee typed as insurance shows on the "Insurance Premium" line, which is then marked provisional with that reason. Fees that would take the net proceeds below zero answer the release preview's own 422 (`errors.fees`, same message) instead of a negative net. `deductions.total_deductions`, `totals.total_deductions`, the three `net_proceeds` and the amount financed include them, so the net proceeds equal the release preview's `net_proceeds` before insurance. Insurance is chosen at release, so it is not on the statement until then. Once released the fees are stored deductions, no longer provisional.

Each deduction item is sorted by its type, as the release journal books it:
- `finance_charges`: `interest`, `items` (`{name, amount}`, plus `provisional`: true and `provisional_reason`: "release_fee" on a fee the release will charge: processing and service fees, catalog fees and any other untyped deduction, then the unitemised remainder as "Other Charges" when positive or "Adjustment" when negative, then before release the provisional catalog fees) and `total`.
- `non_finance_charges`: `items` (`{name, amount, deducted, owed}`, plus `provisional` where noted: notarial fees, taxes (a name containing "tax" or "documentary stamp", or "DST"; a tax-type catalog fee before release is `provisional`: true with `provisional_reason`: "release_fee", all deducted, none owed), and one "Insurance Premium" line whose `amount` is the premium charged, `deducted` what was collected at release and `owed` what the release left unpaid; on a restructure that carried premium over, an "Insurance premium carried from the previous loan" line, all owed, none deducted, with `provisional`: true and `provisional_reason`: "carried_premium" before release, when it is the open source loan's live premium owed, `provisional`: false and no reason after) and `total` (of `amount`).
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
                        new OA\Property(property: 'deductions', type: 'object', properties: [
                            new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'amount', type: 'number', format: 'float'),
                                new OA\Property(property: 'provisional', type: 'boolean', description: 'Present and true on a catalog fee the release will charge (before release only).'),
                                new OA\Property(property: 'provisional_reason', type: 'string', enum: ['release_fee', 'carried_premium'], description: 'Present on a provisional line only: "release_fee" for a catalog fee the release will charge, "carried_premium" for premium a restructure will carry from the loan it closes.'),
                            ])),
                            new OA\Property(property: 'total_deductions', type: 'number', format: 'float'),
                            new OA\Property(property: 'net_proceeds', type: 'number', format: 'float'),
                        ]),
                        new OA\Property(property: 'totals', type: 'object', properties: [
                            new OA\Property(property: 'total_deductions', type: 'number', format: 'float'),
                            new OA\Property(property: 'net_proceeds', type: 'number', format: 'float'),
                            new OA\Property(property: 'total_finance_charges', type: 'number', format: 'float'),
                            new OA\Property(property: 'total_non_finance_charges', type: 'number', format: 'float'),
                            new OA\Property(property: 'unitemised_deductions', type: 'number', format: 'float'),
                        ]),
                        new OA\Property(property: 'finance_charges', type: 'object', properties: [
                            new OA\Property(property: 'interest', type: 'number', format: 'float'),
                            new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'amount', type: 'number', format: 'float'),
                                new OA\Property(property: 'provisional', type: 'boolean', description: 'Present and true on a catalog fee the release will charge (before release only).'),
                                new OA\Property(property: 'provisional_reason', type: 'string', enum: ['release_fee', 'carried_premium'], description: 'Present on a provisional line only: "release_fee" for a catalog fee the release will charge, "carried_premium" for premium a restructure will carry from the loan it closes.'),
                            ])),
                            new OA\Property(property: 'total', type: 'number', format: 'float'),
                        ]),
                        new OA\Property(property: 'non_finance_charges', type: 'object', properties: [
                            new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'amount', type: 'number', format: 'float'),
                                new OA\Property(property: 'deducted', type: 'number', format: 'float'),
                                new OA\Property(property: 'owed', type: 'number', format: 'float'),
                                new OA\Property(property: 'provisional', type: 'boolean', description: 'Present on a line that can still change before release: a catalog fee the release will charge, or premium carried from the loan a restructure closes.'),
                                new OA\Property(property: 'provisional_reason', type: 'string', enum: ['release_fee', 'carried_premium'], description: 'Present on a provisional line only: "release_fee" for a catalog fee the release will charge, "carried_premium" for premium a restructure will carry from the loan it closes.'),
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
            new OA\Response(response: 422, description: 'Loan not eligible for disclosure, or (before release) the catalog fees would take the net proceeds below zero: `errors.fees`, as the release preview answers'),
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
