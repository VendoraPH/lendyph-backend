<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\CashFlowStatementBuilder;
use App\Services\Accounting\EquityChangesBuilder;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The two statements that cannot be regrouped out of a trial balance.
 *
 * There is no balance-sheet or income-statement route here, deliberately and
 * for the same reason there is none on the journals controller: both are pure
 * regroupings of the trial balance and `src/lib/accounting/statements.ts`
 * builds them client-side from the rows `/trial-balance` already returns.
 * Adding endpoints for them would create a second origin for figures that have
 * to agree exactly.
 *
 * These two cannot be done that way. A cash flow statement needs to know which
 * movements were operating, investing or financing — a classification that
 * lives on the account and not in its balance. A statement of changes in equity
 * needs opening balances AND the movements between them, and a trial balance is
 * one instant.
 */
class AccountingStatementController extends Controller
{
    public function __construct(
        private CashFlowStatementBuilder $cashFlow,
        private EquityChangesBuilder $equityChanges,
    ) {}

    #[OA\Get(
        path: '/api/accounting/statements/cash-flow',
        summary: 'Cash flow statement',
        description: 'Direct method: every journal that touched a money account, explained by its own non-cash lines and filed under each account\'s `cash_flow_category`. ONE LINE PER ACCOUNT under each heading, so a classification you disagree with is visible next to its code and amount rather than folded into a subtotal. `net_change` is computed independently from the cash accounts\' opening and closing balances; `is_reconciled` and `unexplained` report whether the sections agree with it. Amounts are INTEGER CENTAVOS.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cash flow statement'),
            new OA\Response(response: 403, description: 'Missing accounting:view'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function cashFlow(): JsonResponse
    {
        $this->authorize('accounting:view');

        $query = $this->range();

        return response()->json([
            'data' => $this->cashFlow->build(
                $query['from'],
                $query['to'],
                $query['branch_id'],
            ),
        ]);
    }

    #[OA\Get(
        path: '/api/accounting/statements/equity-changes',
        summary: 'Statement of changes in equity',
        description: 'Opening balance, additions, deductions and closing balance for each equity account. Every figure is a ledger fact: there is NO synthesised "profit for the period" row, because income and expense accounts hold their balances until somebody posts a closing entry — and when they do, that entry appears here as an addition like any other. Inventing the row would double-count for every organisation that closes its books. Amounts are INTEGER CENTAVOS.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Statement of changes in equity'),
            new OA\Response(response: 403, description: 'Missing accounting:view'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function equityChanges(): JsonResponse
    {
        $this->authorize('accounting:view');

        $query = $this->range();

        return response()->json([
            'data' => $this->equityChanges->build(
                $query['from'],
                $query['to'],
                $query['branch_id'],
            ),
        ]);
    }

    /**
     * The from/to/branch filter every report screen produces.
     *
     * NOTE there is no `per_page` here and no paging: a statement is a whole
     * statement or it is a wrong one. That is also why the two builders
     * aggregate in SQL rather than reading rows — the row count is the whole
     * ledger for the range, and a report endpoint that tried to page it would
     * be reporting a fragment.
     *
     * @return array{from: string, to: string, branch_id: int|null}
     */
    private function range(): array
    {
        $validated = request()->validate([
            // `date_format`, not `date`: a full ISO timestamp or "last month"
            // would both be accepted by `date` and silently change the window.
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        return [
            'from' => $validated['from'],
            'to' => $validated['to'],
            'branch_id' => isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
        ];
    }
}
