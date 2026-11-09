<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Traits\ScopesBranchFilter;
use App\Http\Controllers\Controller;
use App\Services\Accounting\BalanceSheetBuilder;
use App\Services\Accounting\CashFlowStatementBuilder;
use App\Services\Accounting\EquityChangesBuilder;
use App\Services\Accounting\IncomeStatementBuilder;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The financial statements, every figure from the server in integer centavos
 * (Phase 2, decision 10): the balance sheet, the income statement, the cash
 * flow statement and the statement of changes in equity. The browser only
 * displays them.
 *
 * All four read the same posted and reversed journal lines as the trial
 * balance, on `accounting:view`, scoped by the accounting branch rule
 * (ScopesBranchFilter). There is one equity figure: the balance sheet's
 * `total_equity`, which the equity changes' `total_ending` equals on the same
 * date. A statement is a whole document, never paged.
 *
 * The balance-sheet and income-statement routes are in
 * routes/accounting/statements.php; cash flow and equity changes keep their
 * routes in routes/api.php.
 */
class AccountingStatementController extends Controller
{
    use ScopesBranchFilter;

    public function __construct(
        private CashFlowStatementBuilder $cashFlow,
        private EquityChangesBuilder $equityChanges,
        private BalanceSheetBuilder $balanceSheet,
        private IncomeStatementBuilder $incomeStatement,
    ) {}

    #[OA\Get(
        path: '/api/accounting/statements/balance-sheet',
        summary: 'Balance sheet',
        description: 'Assets, liabilities and equity at `as_of`, from the trial balance at `as_of` and at the day before 1 January of its year (calendar fiscal year). A line is `{account_id, account_code, account_name, amount, kind}`; a contra line is negative. Two derived equity lines carry the earnings not yet closed: `prior_period_earnings` (before this year) and `current_year_earnings` (1 January to `as_of`, equal to the income statement\'s `net_income` for that period), each with `account_id` and `account_code` null; a line of 0 is left out. `total_equity` is posted equity plus income less expense. `difference` is assets less liabilities and equity, zero on sound books. Amounts are INTEGER CENTAVOS.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'as_of', in: 'query', required: false, description: 'Y-m-d; today when absent.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'One of the caller\'s branches (another answers 403). Without it, every branch the caller holds; journals with no branch only for branches:view_all.', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Balance sheet'),
            new OA\Response(response: 403, description: 'Missing accounting:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function balanceSheet(): JsonResponse
    {
        $this->authorize('accounting:view');

        $validated = request()->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        return response()->json([
            'data' => $this->balanceSheet->build(
                $validated['as_of'] ?? now()->toDateString(),
                $this->branchScope($validated['branch_id'] ?? null),
            ),
        ]);
    }

    #[OA\Get(
        path: '/api/accounting/statements/income-statement',
        summary: 'Income statement',
        description: 'Each income and expense account\'s movement on posted and reversed journals dated `from` to `to`, netted in its normal direction, in code order; a line of 0 is left out. A line is `{account_id, account_code, account_name, amount}`. `net_income` is `total_income` less `total_expenses`, negative on a loss. Amounts are INTEGER CENTAVOS.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'One of the caller\'s branches (another answers 403). Without it, every branch the caller holds; journals with no branch only for branches:view_all.', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Income statement'),
            new OA\Response(response: 403, description: 'Missing accounting:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function incomeStatement(): JsonResponse
    {
        $this->authorize('accounting:view');

        $query = $this->range();

        return response()->json([
            'data' => $this->incomeStatement->build(
                $query['from'],
                $query['to'],
                $query['branch_id'],
            ),
        ]);
    }

    #[OA\Get(
        path: '/api/accounting/statements/cash-flow',
        summary: 'Cash flow statement',
        description: 'Direct method: every journal that touched a money account, explained by its own non-cash lines and filed under each account\'s `cash_flow_category`. ONE LINE PER ACCOUNT under each heading (`kind: account`), so a classification you disagree with is visible next to its code and amount rather than folded into a subtotal. Loan releases are not split by account: operating carries one `loan_releases` line ("Loans released, net of amounts withheld") and one `restructure_collections` line ("Deductions collected on restructures"), with `account_id` and `account_code` null. What releases withheld from the proceeds is listed by account in `non_cash` ("Withheld at release (non-cash)"), outside every section total. `net_change` is computed independently from the cash accounts\' opening and closing balances; `is_reconciled` and `unexplained` report whether the sections agree with it. Amounts are INTEGER CENTAVOS.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'One of the caller\'s branches (another answers 403). Without it, every branch the caller holds; journals with no branch only for branches:view_all.', schema: new OA\Schema(type: 'integer')),
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
        description: 'Opening balance, additions, deductions and closing balance for each equity account (`kind: account`), then one derived row, "Earnings not yet closed" (`kind: earnings`, `account_id` and `account_code` null): income less expense to the day before `from` and to `to`, with the period\'s movement in `additions` (a profit) or `deductions` (a loss). So `total_ending` equals the balance sheet\'s `total_equity` at `to`. A closing entry moves income and expense into equity inside the same period: the equity account shows it as an addition and the earnings row as a deduction, so no peso counts twice. Amounts are INTEGER CENTAVOS.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'One of the caller\'s branches (another answers 403). Without it, every branch the caller holds; journals with no branch only for branches:view_all.', schema: new OA\Schema(type: 'integer')),
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
     * @return array{from: string, to: string, branch_id: int|list<int>|null}
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
            'branch_id' => $this->branchScope($validated['branch_id'] ?? null),
        ];
    }
}
