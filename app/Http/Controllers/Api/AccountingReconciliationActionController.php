<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ReconcileLineAsExpenseRequest;
use App\Http\Requests\Accounting\ReconcileLineAsTransferRequest;
use App\Http\Requests\Accounting\ReconcileLineToSuspenseRequest;
use App\Models\AccountingReconciliation;
use App\Services\Accounting\ReconciliationActions;
use App\Services\Accounting\ReconciliationMatcher;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Recording an unmatched statement line as the entry the books are missing,
 * matched to it in the same step (Phase 2, Accounting round C, C5).
 *
 * Unlike the rest of reconciliation, these DO write journals, each through the
 * recorder that owns its record and each under that record's own permission.
 * Each action is still a deliberate entry someone chose, never an adjustment
 * the module slips in to make a difference close.
 *
 * Every route needs `accounting:reconcile` + `branches:view_all` (head office),
 * checked in the form requests together with the record's permission. Each
 * answers 201 with the reconciliation exactly as GET
 * /accounting/reconciliations/{id} answers it, plus a `created` block naming
 * the new record and its journal.
 */
class AccountingReconciliationActionController extends Controller
{
    public function __construct(
        private ReconciliationActions $actions,
        private ReconciliationMatcher $matcher,
    ) {}

    #[OA\Post(
        path: '/api/accounting/reconciliations/{reconciliation}/lines/{line}/expense',
        summary: 'Record a statement line as an expense',
        description: 'Money out only (`amount` < 0). Records an expense of −`amount` centavos paid from the reconciled account (Dr expense / Cr reconciled), dated the line\'s date, no branch, reference = the line\'s `external_reference`, and matches the line to the new bank or cash line. Needs accounting:reconcile, branches:view_all and expenses:create.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'reconciliation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'line', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['expense_account_id', 'payee'],
            properties: [
                new OA\Property(property: 'expense_account_id', type: 'integer'),
                new OA\Property(property: 'payee', type: 'string', maxLength: 160),
                new OA\Property(property: 'description', type: 'string', maxLength: 500, nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: '{data: reconciliation, created: {type: "expense", id, journal_id, journal_no}}'),
            new OA\Response(response: 403, description: 'Missing accounting:reconcile, branches:view_all or expenses:create'),
            new OA\Response(response: 404, description: 'The line does not belong to this reconciliation'),
            new OA\Response(response: 409, description: 'This statement line is already matched'),
            new OA\Response(response: 422, description: 'Money in, a closed period, the line outside the reconciliation\'s dates, or a wrong account'),
        ],
    )]
    public function expense(ReconcileLineAsExpenseRequest $request, AccountingReconciliation $reconciliation, int $line): JsonResponse
    {
        $created = $this->actions->recordAsExpense($reconciliation, $line, $request->validated(), $request->user()?->id);

        return $this->answer($reconciliation, $created);
    }

    #[OA\Post(
        path: '/api/accounting/reconciliations/{reconciliation}/lines/{line}/transfer',
        summary: 'Record a statement line as a transfer',
        description: 'Money in moves from the counter account into the reconciled one; money out the other way. |`amount`| centavos, dated the line\'s date, no branch. The counter account must be a money account other than the reconciled one. Needs accounting:reconcile, branches:view_all and cash_accounts:transfer.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'reconciliation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'line', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['counter_account_id'],
            properties: [
                new OA\Property(property: 'counter_account_id', type: 'integer'),
                new OA\Property(property: 'description', type: 'string', maxLength: 500, nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: '{data: reconciliation, created: {type: "transfer", id, journal_id, journal_no}}'),
            new OA\Response(response: 403, description: 'Missing accounting:reconcile, branches:view_all or cash_accounts:transfer'),
            new OA\Response(response: 404, description: 'The line does not belong to this reconciliation'),
            new OA\Response(response: 409, description: 'This statement line is already matched'),
            new OA\Response(response: 422, description: 'A closed period, the line outside the reconciliation\'s dates, or a wrong counter account'),
        ],
    )]
    public function transfer(ReconcileLineAsTransferRequest $request, AccountingReconciliation $reconciliation, int $line): JsonResponse
    {
        $created = $this->actions->recordAsTransfer($reconciliation, $line, $request->validated(), $request->user()?->id);

        return $this->answer($reconciliation, $created);
    }

    #[OA\Post(
        path: '/api/accounting/reconciliations/{reconciliation}/lines/{line}/suspense',
        summary: 'Record a statement line to Suspense',
        description: 'Posts a journal immediately: money in Dr reconciled / Cr suspense, money out Dr suspense / Cr reconciled. Source bank_transaction on a bank account, cash_transaction otherwise; dated the line\'s date, no branch, reference = `external_reference` or "REC-<reconciliation id>-<line id>". Needs accounting:reconcile, branches:view_all, journals:create and journals:post.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'reconciliation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'line', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'description', type: 'string', maxLength: 255, nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: '{data: reconciliation, created: {type: "journal", id, journal_id, journal_no}}'),
            new OA\Response(response: 403, description: 'Missing accounting:reconcile, branches:view_all, journals:create or journals:post'),
            new OA\Response(response: 404, description: 'The line does not belong to this reconciliation'),
            new OA\Response(response: 409, description: 'This statement line is already matched'),
            new OA\Response(response: 422, description: 'A closed period, the line outside the reconciliation\'s dates, or no Suspense account mapped'),
        ],
    )]
    public function suspense(ReconcileLineToSuspenseRequest $request, AccountingReconciliation $reconciliation, int $line): JsonResponse
    {
        $created = $this->actions->recordToSuspense($reconciliation, $line, $request->validated(), $request->user()?->id);

        return $this->answer($reconciliation, $created);
    }

    /** @param  array{type: string, id: int, journal_id: int, journal_no: string}  $created */
    private function answer(AccountingReconciliation $reconciliation, array $created): JsonResponse
    {
        return response()->json([
            'data' => $this->matcher->present($reconciliation->refresh()),
            'created' => $created,
        ], 201);
    }
}
