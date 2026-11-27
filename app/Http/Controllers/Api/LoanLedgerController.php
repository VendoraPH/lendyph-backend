<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\LoanLedgerService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class LoanLedgerController extends Controller
{
    public function __construct(private LoanLedgerService $ledger) {}

    #[OA\Get(
        path: '/api/loans/{id}/ledger',
        summary: 'Loan ledger with running balances',
        description: 'Every event that moved what the loan owes, in date order and then posting order, each with its own figures and the running balances after it, worked out by the server. Payment rows read the repayment\'s own `*_applied` columns (an extension\'s collection is one `extension_payment` row, the repayment the extension names, never counted again from its credit entry); extension charges are the debit entries; the interest opening is the original schedule\'s interest. Payment, void and charge rows state their figures as positive amounts and `kind` says which way they move the balances; an `adjustment` row\'s figures are the signed change it made (negative lowers). The balances exclude the insurance premium owed, which payment rows show in their own `insurance` column. The last row\'s balances equal `closing`, which equals GET /loans/{id}/summary `outstanding_principal`, `outstanding_interest` and `outstanding_penalty`. Whole centavos throughout. GET /loans/{id}/ledger-entries is unchanged.',
        tags: ['Loans'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The ledger', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'rows', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'date', type: 'string', format: 'date'),
                        new OA\Property(property: 'kind', type: 'string', enum: ['release', 'repayment', 'extension_payment', 'extension_charge', 'penalty_charge', 'adjustment', 'void'], description: '`penalty_charge` is the late penalty charged on a period, dated the first day past its grace. `adjustment` covers penalty waivers, the penalty a closure wrote off with a period ("Penalty closed with the period"), balance adjustments, schedule restructures and term extensions (the last one carries the interest the rebuilt schedule changed by), a balance moved to a restructured loan, what an imported loan was paid before the import, and, only where recorded rows don\'t reach the schedule (older data), "Balance restated to the schedule", dated today.'),
                        new OA\Property(property: 'repayment_id', type: 'integer', nullable: true),
                        new OA\Property(property: 'loan_adjustment_id', type: 'integer', nullable: true),
                        new OA\Property(property: 'description', type: 'string'),
                        new OA\Property(property: 'principal', type: 'number'),
                        new OA\Property(property: 'interest', type: 'number'),
                        new OA\Property(property: 'penalty', type: 'number'),
                        new OA\Property(property: 'insurance', type: 'number', description: 'The insurance premium a payment collected'),
                        new OA\Property(property: 'scb', type: 'number', description: 'Share capital build-up: on the release row the schedule\'s total build-up; on a payment or void row the share capital that payment credited'),
                        new OA\Property(property: 'balances', type: 'object', description: 'After this row. Excludes the insurance premium owed.', properties: [
                            new OA\Property(property: 'principal', type: 'number'),
                            new OA\Property(property: 'interest', type: 'number'),
                            new OA\Property(property: 'penalty', type: 'number'),
                            new OA\Property(property: 'scb', type: 'number', description: 'Build-up the schedule still awaits, never below 0'),
                        ]),
                    ])),
                    new OA\Property(property: 'closing', type: 'object', properties: [
                        new OA\Property(property: 'principal', type: 'number'),
                        new OA\Property(property: 'interest', type: 'number'),
                        new OA\Property(property: 'penalty', type: 'number'),
                        new OA\Property(property: 'scb', type: 'number'),
                    ]),
                ]),
            ])),
            new OA\Response(response: 403, description: 'Missing loans:view permission'),
            new OA\Response(response: 404, description: 'Loan not found'),
        ],
    )]
    public function show(Loan $loan): JsonResponse
    {
        $this->authorize('loans:view');

        return response()->json(['data' => $this->ledger->forLoan($loan)]);
    }
}
