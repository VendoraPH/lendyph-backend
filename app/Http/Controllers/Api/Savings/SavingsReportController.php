<?php

namespace App\Http\Controllers\Api\Savings;

use App\Http\Controllers\Api\Traits\CsvExportTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\Report\SavingsExportRequest;
use App\Http\Requests\Savings\Report\SavingsReportRequest;
use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Services\BranchAccess;
use App\Services\Savings\Reports\GlControl;
use App\Services\Savings\Reports\SavingsReports;
use App\Services\Savings\Reports\StatementBuilder;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The savings reports (design §2): `reports:view` plus `savings:view`, CSV
 * with `reports:export`. Lists answer one page with `totals` for the whole
 * filter (also under `meta.totals`); a total is never one page's rows added
 * up. A branch user reports on their own branches (another `branch_id` is
 * 403, another branch's account 404).
 */
class SavingsReportController extends Controller
{
    use CsvExportTrait;

    public function __construct(
        private readonly SavingsReports $reports,
        private readonly StatementBuilder $statements,
        private readonly GlControl $control,
        private readonly BranchAccess $branches,
    ) {}

    #[OA\Get(
        path: '/api/reports/savings/balances',
        summary: 'Savings Balances',
        description: 'Every savings account open on `as_of` (default today) and its balance by business date. Holds, pending approvals, the available balance and accrued interest are today\'s figures (null for a past day). `totals`: account_count, total_balance, total_hold, total_pending, total_available, total_accrued_interest.',
        tags: ['Savings Reports'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 1000))],
        responses: [
            new OA\Response(response: 200, description: 'A page of accounts with totals'),
            new OA\Response(response: 403, description: 'Missing reports:view or savings:view, or another branch'),
            new OA\Response(response: 422, description: 'An invalid filter, or `as_of` after today'),
        ],
    )]
    public function balances(SavingsReportRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $page = $this->reports->balancesQuery($filters)->paginate($this->perPage($filters));

        return $this->list($page, $this->reports->balanceRows($page->getCollection(), $filters), $this->reports->balanceTotals($filters), ['as_of' => $this->reports->asOf($filters)]);
    }

    #[OA\Get(
        path: '/api/reports/savings/transactions',
        summary: 'Savings Transactions',
        description: 'Every savings transaction dated in the period (default this month to today), oldest first. `totals`: count, total_credit, total_debit.',
        tags: ['Savings Reports'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 1000))],
        responses: [
            new OA\Response(response: 200, description: 'A page of transactions with totals'),
            new OA\Response(response: 403, description: 'Missing reports:view or savings:view, or another branch'),
            new OA\Response(response: 422, description: 'An invalid filter'),
        ],
    )]
    public function transactions(SavingsReportRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $page = $this->reports->transactionsQuery($filters)->paginate($this->perPage($filters));

        return $this->list($page, $page->getCollection()->map(fn (SavingsTransaction $row) => $this->reports->transactionRow($row))->all(), $this->reports->transactionTotals($filters), $this->range($filters));
    }

    #[OA\Get(
        path: '/api/reports/savings/time-deposit-maturities',
        summary: 'Time Deposit Maturities',
        description: 'Time deposits maturing in the period (default this month), soonest first; pre-terminated ones are left out. `totals`: count, total_principal, total_net_interest, total_maturity_amount and payout_amount (what is paid out rather than rolled over).',
        tags: ['Savings Reports'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 1000))],
        responses: [
            new OA\Response(response: 200, description: 'A page of time deposits with totals'),
            new OA\Response(response: 403, description: 'Missing reports:view or savings:view, or another branch'),
            new OA\Response(response: 422, description: 'An invalid filter'),
        ],
    )]
    public function timeDepositMaturities(SavingsReportRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $page = $this->reports->maturitiesQuery($filters)->paginate($this->perPage($filters));

        return $this->list($page, $page->getCollection()->map(fn (TimeDeposit $deposit) => $this->reports->maturityRow($deposit))->all(), $this->reports->maturityTotals($filters), $this->range($filters, true));
    }

    #[OA\Get(
        path: '/api/reports/savings/interest-postings',
        summary: 'Savings Interest Postings',
        description: 'Interest runs posted in the period (by the day they posted), one row per product and branch. `totals`: count, total_gross, total_tax, total_net.',
        tags: ['Savings Reports'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 1000))],
        responses: [
            new OA\Response(response: 200, description: 'A page of runs with totals'),
            new OA\Response(response: 403, description: 'Missing reports:view or savings:view, or another branch'),
            new OA\Response(response: 422, description: 'An invalid filter'),
        ],
    )]
    public function interestPostings(SavingsReportRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $page = $this->reports->interestPostingsQuery($filters)->paginate($this->perPage($filters));

        return $this->list($page, $page->getCollection()->map(fn (SavingsInterestRun $run) => $this->reports->interestPostingRow($run))->all(), $this->reports->interestPostingTotals($filters), $this->range($filters));
    }

    #[OA\Get(
        path: '/api/reports/savings/dormant',
        summary: 'Dormant Savings Accounts',
        description: 'Accounts dormant today. `totals`: account_count, total_balance.',
        tags: ['Savings Reports'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 1000))],
        responses: [
            new OA\Response(response: 200, description: 'A page of accounts with totals'),
            new OA\Response(response: 403, description: 'Missing reports:view or savings:view, or another branch'),
            new OA\Response(response: 422, description: 'An invalid filter'),
        ],
    )]
    public function dormant(SavingsReportRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $page = $this->reports->dormantQuery($filters)->paginate($this->perPage($filters));

        return $this->list($page, $page->getCollection()->map(fn (SavingsAccount $account) => $this->reports->dormantRow($account))->all(), $this->reports->dormantTotals($filters), ['as_of' => now()->toDateString()]);
    }

    #[OA\Get(
        path: '/api/reports/savings/statement/{account}',
        summary: 'Savings Statement',
        description: 'One account for the period (default its opening day to today): `account`, `opening_balance`, `rows` with the running `balance_after`, `totals` {credit, debit, count} and `closing_balance`.',
        tags: ['Savings Reports'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [
            new OA\Response(response: 200, description: 'The statement'),
            new OA\Response(response: 403, description: 'Missing reports:view or savings:view'),
            new OA\Response(response: 404, description: 'No such account in the caller\'s branches'),
            new OA\Response(response: 422, description: 'An invalid filter'),
        ],
    )]
    public function statement(SavingsReportRequest $request, int $account): JsonResponse
    {
        $filters = $request->filters();
        $model = $this->branches->constrain(SavingsAccount::query(), $request->user(), 'branch_id')->findOrFail($account);

        return response()->json(['success' => true, 'data' => $this->statements->build($model, $filters['date_from'] ?? null, $filters['date_to'] ?? null)]);
    }

    #[OA\Get(
        path: '/api/reports/savings/gl-control',
        summary: 'Savings GL Control',
        description: 'The savings subledger against GL Savings Deposits (2050) and Time Deposits (2060), per branch, as of the end of `as_of` (default today). Each row: branch, account, subledger_balance, gl_balance and difference (subledger less GL); `totals` and `is_balanced`.',
        tags: ['Savings Reports'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The control'),
            new OA\Response(response: 403, description: 'Missing reports:view or savings:view, or another branch'),
            new OA\Response(response: 422, description: 'An invalid filter, or `as_of` after today'),
        ],
    )]
    public function glControl(SavingsReportRequest $request): JsonResponse
    {
        $filters = $request->filters();

        return response()->json(['success' => true, 'data' => $this->controlFor($filters)]);
    }

    #[OA\Get(path: '/api/reports/savings/balances/export', summary: 'Export Savings Balances as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view')])]
    public function exportBalances(SavingsExportRequest $request): StreamedResponse
    {
        $filters = $request->filters();
        $rows = (function () use ($filters) {
            foreach ($this->reports->balancesQuery($filters)->reorder()->lazyById(500)->chunk(500) as $chunk) {
                foreach ($this->reports->balanceRows($chunk->collect()->values(), $filters) as $row) {
                    yield [$row['account_number'], $row['borrower_name'], $row['borrower_code'], $row['product_name'], $row['branch_name'], $row['status'], $row['balance'], $row['hold_amount'], $row['pending_amount'], $row['available_balance'], $row['accrued_interest'], $row['last_member_activity_on']];
                }
            }
        })();

        return $this->streamCsv('savings-balances.csv', ['Account #', 'Member', 'Member Code', 'Product', 'Branch', 'Status', 'Balance', 'On Hold', 'Pending Approval', 'Available', 'Accrued Interest', 'Last Activity'], $rows);
    }

    #[OA\Get(path: '/api/reports/savings/transactions/export', summary: 'Export Savings Transactions as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view')])]
    public function exportTransactions(SavingsExportRequest $request): StreamedResponse
    {
        return $this->csv('savings-transactions.csv', ['Date', 'Value Date', 'Reference', 'Account #', 'Member', 'Branch', 'Type', 'Method', 'In', 'Out', 'Balance'], $this->reports->transactionsQuery($request->filters()), function (SavingsTransaction $row): array {
            $r = $this->reports->transactionRow($row);

            return [$r['date'], $r['value_date'], $r['reference'], $r['account_number'], $r['borrower_name'], $r['branch_name'], $r['type'], $r['method'], $r['credit'], $r['debit'], $r['balance_after']];
        });
    }

    #[OA\Get(path: '/api/reports/savings/time-deposit-maturities/export', summary: 'Export Time Deposit Maturities as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view')])]
    public function exportTimeDepositMaturities(SavingsExportRequest $request): StreamedResponse
    {
        return $this->csv('time-deposit-maturities.csv', ['Maturity', 'Certificate #', 'Member', 'Product', 'Branch', 'Principal', 'Rate', 'Days', 'Net Interest', 'Due at Maturity', 'Instruction', 'Status'], $this->reports->maturitiesQuery($request->filters()), function (TimeDeposit $deposit): array {
            $r = $this->reports->maturityRow($deposit);

            return [$r['maturity_date'], $r['certificate_number'], $r['borrower_name'], $r['product_name'], $r['branch_name'], $r['principal'], $r['annual_rate'].'%', $r['term_days'], $r['net_interest'], $r['maturity_amount'], $r['maturity_instruction'], $r['status']];
        });
    }

    #[OA\Get(path: '/api/reports/savings/interest-postings/export', summary: 'Export Savings Interest Postings as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view')])]
    public function exportInterestPostings(SavingsExportRequest $request): StreamedResponse
    {
        return $this->csv('savings-interest-postings.csv', ['From', 'To', 'Product', 'Branch', 'Accounts', 'Gross Interest', 'Tax Withheld', 'Net Credited', 'Posted'], $this->reports->interestPostingsQuery($request->filters()), function (SavingsInterestRun $run): array {
            $r = $this->reports->interestPostingRow($run);

            return [$r['period_start'], $r['period_end'], $r['product_name'], $r['branch_name'], $r['account_count'], $r['total_gross'], $r['total_tax'], $r['total_net'], $run->posted_at?->toDateString()];
        });
    }

    #[OA\Get(path: '/api/reports/savings/dormant/export', summary: 'Export Dormant Savings Accounts as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view')])]
    public function exportDormant(SavingsExportRequest $request): StreamedResponse
    {
        return $this->csv('dormant-savings-accounts.csv', ['Account #', 'Member', 'Product', 'Branch', 'Balance', 'Last Activity', 'Dormant Since'], $this->reports->dormantQuery($request->filters()), function (SavingsAccount $account): array {
            $r = $this->reports->dormantRow($account);

            return [$r['account_number'], $r['borrower_name'], $r['product_name'], $r['branch_name'], $r['balance'], $r['last_member_activity_on'], $r['dormant_since']];
        });
    }

    #[OA\Get(path: '/api/reports/savings/gl-control/export', summary: 'Export the Savings GL Control as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view')])]
    public function exportGlControl(SavingsExportRequest $request): StreamedResponse
    {
        $control = $this->controlFor($request->filters());
        $rows = array_map(fn (array $row) => [$control['as_of'], $row['branch_name'], $row['account_code'], $row['account_name'], $row['subledger_balance'], $row['gl_balance'], $row['difference']], $control['rows']);

        return $this->streamCsv('savings-gl-control.csv', ['As Of', 'Branch', 'Account', 'Account Name', 'Savings Subledger', 'General Ledger', 'Difference'], $rows);
    }

    /** @return array<string, mixed> */
    private function controlFor(array $filters): array
    {
        $control = $this->control->build($filters['as_of'] ?? now()->toDateString(), $filters['branch_ids'], $filters['branch_id'] ?? null);
        unset($control['differences']);

        return $control;
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $extra
     */
    private function list($page, array $rows, array $totals, array $extra): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'totals' => $totals,
                ...$extra,
            ],
            'totals' => $totals,
            ...$extra,
        ]);
    }

    /**
     * @param  list<string>  $headers
     * @param  Closure(mixed): array<int, mixed>  $map
     */
    private function csv(string $filename, array $headers, Builder $query, Closure $map): StreamedResponse
    {
        return $this->streamCsv($filename, $headers, $query->lazy(500)->map($map));
    }

    /** @return array{date_from: string, date_to: string} */
    private function range(array $filters, bool $wholeMonth = false): array
    {
        [$from, $to] = $this->reports->period($filters, $wholeMonth);

        return ['date_from' => $from, 'date_to' => $to];
    }

    private function perPage(array $filters): int
    {
        return (int) ($filters['per_page'] ?? 25);
    }
}
