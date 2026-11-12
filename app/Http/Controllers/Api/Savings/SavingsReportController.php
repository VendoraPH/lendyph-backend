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
use App\Services\Savings\Dashboard\DepositFigures;
use App\Services\Savings\Dashboard\DepositSummary;
use App\Services\Savings\Reports\GlControl;
use App\Services\Savings\Reports\MemberStatement;
use App\Services\Savings\Reports\SavingsReports;
use App\Services\Savings\Reports\StatementBuilder;
use App\Services\Savings\SavingsMath;
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

    /** Interest run statuses in words, for the CSV (the screen maps its own). */
    private const RUN_STATUS_WORDS = ['posted' => 'Posted', 'reversed' => 'Reversed'];

    /**
     * The other keys a savings CSV shows, in the savings screens' words
     * (savings-rules.ts and time-deposit-rules.ts), so a CSV never reads a raw
     * key such as `active`. A key with no words reads "Other".
     */
    private const ACCOUNT_STATUS_WORDS = ['active' => 'Active', 'dormant' => 'Dormant', 'frozen' => 'Frozen', 'closed' => 'Closed'];

    private const TRANSACTION_TYPE_WORDS = [
        'deposit' => 'Deposit', 'withdrawal' => 'Withdrawal', 'transfer_in' => 'Transfer in', 'transfer_out' => 'Transfer out',
        'interest' => 'Interest', 'withholding_tax' => 'Withholding tax', 'closing' => 'Closing payout', 'reversal' => 'Reversal',
        'fee' => 'Excess-withdrawal fee', 'share_capital_transfer' => 'Transfer to share capital',
    ];

    private const METHOD_WORDS = ['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'gcash' => 'GCash', 'maya' => 'Maya', 'savings' => 'Savings account'];

    private const TIME_DEPOSIT_STATUS_WORDS = [
        'active' => 'Active', 'matured' => 'Matured', 'withdrawn' => 'Withdrawn', 'rolled_over' => 'Rolled over', 'preterminated' => 'Pre-terminated',
    ];

    private const MATURITY_INSTRUCTION_WORDS = [
        'payout' => 'Pay out principal and interest',
        'rollover_principal' => 'Roll over principal, pay out interest',
        'rollover_all' => 'Roll over principal and interest',
    ];

    public function __construct(
        private readonly SavingsReports $reports,
        private readonly StatementBuilder $statements,
        private readonly GlControl $control,
        private readonly BranchAccess $branches,
        private readonly DepositSummary $summary,
        private readonly MemberStatement $memberStatements,
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
        description: 'Time deposits maturing in the period (default this month), soonest first; pre-terminated ones are left out. `totals`: listed_count (every placement listed), and count, total_principal, total_net_interest, total_maturity_amount and payout_amount (what is paid out rather than rolled over), which count only placements still owed (active, or matured and not settled); a placement already paid out or rolled over is listed with its status but counted in no money total.',
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
        description: 'Interest runs posted in the period (by the day they posted), one row per product and branch, reversed ones included with their status. `totals`: count, reversed_count, and total_gross, total_tax and total_net of the runs still posted.',
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
                    yield [$row['account_number'], $row['borrower_name'], $row['borrower_code'], $row['product_name'], $row['branch_name'], self::inWords(self::ACCOUNT_STATUS_WORDS, $row['status']), $row['balance'], $row['hold_amount'], $row['pending_amount'], $row['available_balance'], $row['accrued_interest'], $row['last_member_activity_on']];
                }
            }
        })();

        return $this->savingsCsv('savings-balances.csv', ['Account #', 'Member', 'Member Code', 'Product', 'Branch', 'Status', 'Balance', 'On Hold', 'Pending Approval', 'Available', 'Accrued Interest', 'Last Activity'], $rows);
    }

    #[OA\Get(path: '/api/reports/savings/transactions/export', summary: 'Export Savings Transactions as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view')])]
    public function exportTransactions(SavingsExportRequest $request): StreamedResponse
    {
        return $this->csv('savings-transactions.csv', ['Date', 'Value Date', 'Reference', 'Account #', 'Member', 'Branch', 'Type', 'Method', 'In', 'Out', 'Balance'], $this->reports->transactionsQuery($request->filters()), function (SavingsTransaction $row): array {
            $r = $this->reports->transactionRow($row);

            return [$r['date'], $r['value_date'], $r['reference'], $r['account_number'], $r['borrower_name'], $r['branch_name'], self::inWords(self::TRANSACTION_TYPE_WORDS, $r['type']), self::inWords(self::METHOD_WORDS, $r['method']), $r['credit'], $r['debit'], $r['balance_after']];
        });
    }

    #[OA\Get(path: '/api/reports/savings/time-deposit-maturities/export', summary: 'Export Time Deposit Maturities as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view')])]
    public function exportTimeDepositMaturities(SavingsExportRequest $request): StreamedResponse
    {
        return $this->csv('time-deposit-maturities.csv', ['Maturity', 'Certificate #', 'Member', 'Product', 'Branch', 'Principal', 'Rate', 'Days', 'Net Interest', 'Due at Maturity', 'Instruction', 'Status'], $this->reports->maturitiesQuery($request->filters()), function (TimeDeposit $deposit): array {
            $r = $this->reports->maturityRow($deposit);

            return [$r['maturity_date'], $r['certificate_number'], $r['borrower_name'], $r['product_name'], $r['branch_name'], $r['principal'], self::percent($r['annual_rate']), $r['term_days'], $r['net_interest'], $r['maturity_amount'], self::inWords(self::MATURITY_INSTRUCTION_WORDS, $r['maturity_instruction']), self::inWords(self::TIME_DEPOSIT_STATUS_WORDS, $r['status'])];
        });
    }

    #[OA\Get(path: '/api/reports/savings/interest-postings/export', summary: 'Export Savings Interest Postings as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view')])]
    public function exportInterestPostings(SavingsExportRequest $request): StreamedResponse
    {
        return $this->csv('savings-interest-postings.csv', ['From', 'To', 'Product', 'Branch', 'Status', 'Accounts', 'Gross Interest', 'Tax Withheld', 'Net Credited', 'Posted', 'Reversed'], $this->reports->interestPostingsQuery($request->filters()), function (SavingsInterestRun $run): array {
            $r = $this->reports->interestPostingRow($run);

            return [$r['period_start'], $r['period_end'], $r['product_name'], $r['branch_name'], self::inWords(self::RUN_STATUS_WORDS, $run->status), $r['account_count'], $r['total_gross'], $r['total_tax'], $r['total_net'], $run->posted_at?->toDateString(), $run->reversed_at?->toDateString()];
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

        return $this->savingsCsv('savings-gl-control.csv', ['As Of', 'Branch', 'Account', 'Account Name', 'Savings Subledger', 'General Ledger', 'Difference'], $rows);
    }

    // ── Gap round S5: the rest of the Savings task's TASK 36 ──

    #[OA\Get(
        path: '/api/reports/savings/accounts',
        summary: 'Savings Account Listing',
        description: 'Every savings account in the caller\'s branches, closed ones too, by account number, with its balance today, opening and closing dates, dormancy and account officer. `totals`: account_count, active_count, dormant_count, frozen_count, closed_count, total_balance.',
        tags: ['Savings Reports'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 1000))],
        responses: [new OA\Response(response: 200, description: 'A page of accounts with totals'), new OA\Response(response: 403, description: 'Missing reports:view or savings:view, or another branch'), new OA\Response(response: 422, description: 'An invalid filter')],
    )]
    public function accounts(SavingsReportRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $page = $this->reports->accountListQuery($filters)->paginate($this->perPage($filters));

        return $this->list($page, $page->getCollection()->map(fn (SavingsAccount $account) => $this->reports->accountListRow($account))->all(), $this->reports->accountListTotals($filters), ['as_of' => now()->toDateString()]);
    }

    #[OA\Get(
        path: '/api/reports/savings/deposits',
        summary: 'Savings Deposits',
        description: 'Deposits dated in the period (default this month to today), oldest first; money moved from a time deposit is left out. A deposit reversed by the period\'s end is listed with `reversed` and counts in no money total; one reversed later counts, as the period stood at its end. `totals`: count, reversed_count, total_credit, total_debit, total_amount.',
        tags: ['Savings Reports'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 1000))],
        responses: [new OA\Response(response: 200, description: 'A page of deposits with totals'), new OA\Response(response: 403, description: 'Missing reports:view or savings:view, or another branch'), new OA\Response(response: 422, description: 'An invalid filter')],
    )]
    public function deposits(SavingsReportRequest $request): JsonResponse
    {
        $filters = $request->filters();

        return $this->flowList($this->reports->depositsQuery($filters), $filters);
    }

    #[OA\Get(
        path: '/api/reports/savings/withdrawals',
        summary: 'Savings Withdrawals',
        description: 'Withdrawals and closing payouts dated in the period (default this month to today), oldest first; money moved into a time deposit is left out. A reversed one is listed with `reversed` and counts in no money total. `totals`: count, reversed_count, total_credit, total_debit, total_amount.',
        tags: ['Savings Reports'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 1000))],
        responses: [new OA\Response(response: 200, description: 'A page of withdrawals with totals'), new OA\Response(response: 403, description: 'Missing reports:view or savings:view, or another branch'), new OA\Response(response: 422, description: 'An invalid filter')],
    )]
    public function withdrawals(SavingsReportRequest $request): JsonResponse
    {
        $filters = $request->filters();

        return $this->flowList($this->reports->withdrawalsQuery($filters), $filters);
    }

    #[OA\Get(
        path: '/api/reports/savings/large-transactions',
        summary: 'Large Savings Transactions',
        description: 'Deposits, withdrawals and closing payouts dated in the period of the large-transaction alert\'s amount (savings settings) or more, the rows the alert is sent for; money moved to or from a time deposit is left out. `threshold` is that amount. A reversed one is listed with `reversed` and counts in no money total. `totals`: count, reversed_count, total_credit, total_debit, total_amount.',
        tags: ['Savings Reports'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 1000))],
        responses: [new OA\Response(response: 200, description: 'A page of transactions with totals'), new OA\Response(response: 403, description: 'Missing reports:view or savings:view, or another branch'), new OA\Response(response: 422, description: 'An invalid filter')],
    )]
    public function largeTransactions(SavingsReportRequest $request): JsonResponse
    {
        $filters = $request->filters();

        return $this->flowList($this->reports->largeTransactionsQuery($filters), $filters, ['threshold' => SavingsMath::pesos($this->reports->largeTransactionThreshold())]);
    }

    #[OA\Get(
        path: '/api/reports/savings/time-deposits',
        summary: 'Time Deposits Outstanding',
        description: 'Time deposits outstanding at the end of `as_of` (default today): placed by then and not settled by then, soonest maturity first, with the days to maturity from `as_of`. `totals`: count, total_principal (the GL control\'s 2060 subledger), total_net_interest, total_maturity_amount.',
        tags: ['Savings Reports'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 1000))],
        responses: [new OA\Response(response: 200, description: 'A page of time deposits with totals'), new OA\Response(response: 403, description: 'Missing reports:view or savings:view, or another branch'), new OA\Response(response: 422, description: 'An invalid filter, or `as_of` after today')],
    )]
    public function timeDeposits(SavingsReportRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $page = $this->reports->timeDepositsQuery($filters)->paginate($this->perPage($filters));

        return $this->list($page, $page->getCollection()->map(fn (TimeDeposit $deposit) => $this->reports->timeDepositRow($deposit, $filters))->all(), $this->reports->timeDepositTotals($filters), ['as_of' => $this->reports->asOf($filters)]);
    }

    #[OA\Get(
        path: '/api/reports/savings/interest-accrual',
        summary: 'Savings Interest Accrual',
        description: 'Interest earned and not yet credited, today, on every account not closed: from the day after it was last credited (or its opening) to yesterday, its average daily balance, gross interest, tax and net, as an interest run works it out. Shown only; nothing posts until a run credits it. `totals`: account_count, total_gross_interest, total_tax, total_net_interest.',
        tags: ['Savings Reports'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 1000))],
        responses: [new OA\Response(response: 200, description: 'A page of accounts with totals'), new OA\Response(response: 403, description: 'Missing reports:view or savings:view, or another branch'), new OA\Response(response: 422, description: 'An invalid filter')],
    )]
    public function interestAccrual(SavingsReportRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $page = $this->reports->interestAccrualQuery($filters)->paginate($this->perPage($filters));

        return $this->list($page, $this->reports->interestAccrualRows($page->getCollection()), $this->reports->interestAccrualTotals($filters), ['as_of' => now()->toDateString()]);
    }

    #[OA\Get(
        path: '/api/reports/savings/account-activity',
        summary: 'Savings Account Activity',
        description: 'Each account open at some point in the period (default this month to today): its balance before the period, money in and out in it (every row, reversals too), its number of rows, its closing balance and its last row\'s date. `totals`: account_count, transaction_count, total_opening_balance, total_credits, total_debits, total_closing_balance.',
        tags: ['Savings Reports'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 1000))],
        responses: [new OA\Response(response: 200, description: 'A page of accounts with totals'), new OA\Response(response: 403, description: 'Missing reports:view or savings:view, or another branch'), new OA\Response(response: 422, description: 'An invalid filter')],
    )]
    public function accountActivity(SavingsReportRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $page = $this->reports->accountActivityQuery($filters)->paginate($this->perPage($filters));

        return $this->list($page, $this->reports->accountActivityRows($page->getCollection(), $filters), $this->reports->accountActivityTotals($filters), $this->range($filters));
    }

    #[OA\Get(
        path: '/api/reports/savings/member-statement/{borrower}',
        summary: 'Member Deposit Statement',
        description: 'One member\'s savings accounts in the caller\'s branches, each as its own statement for the period (default the member\'s first account opening to today), and the member\'s time deposits outstanding at some point in it. `member` carries the id, name, code and branch only. `totals`: account_count, opening_balance, credit, debit, closing_balance, time_deposit_count and time_deposits (outstanding at `date_to`), total_deposits.',
        tags: ['Savings Reports'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'borrower', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The statement'), new OA\Response(response: 403, description: 'Missing reports:view or savings:view'), new OA\Response(response: 404, description: 'No such member in the caller\'s branches'), new OA\Response(response: 422, description: 'An invalid filter')],
    )]
    public function memberStatement(SavingsReportRequest $request, int $borrower): JsonResponse
    {
        $filters = $request->filters();
        $member = $this->memberStatements->member($request->user(), $borrower);

        return response()->json(['success' => true, 'data' => $this->memberStatements->build($request->user(), $member, $filters)]);
    }

    #[OA\Get(
        path: '/api/reports/savings/summary',
        summary: 'Savings Deposit Summary',
        description: 'The period (default this month to today) by branch (default) or by product (`group_by`). Each row and `totals`: opening_balance, deposit_count, deposits, withdrawal_count, withdrawals, interest_credited, tax_withheld, fee_count, fees_charged (excess-withdrawal fees, income, never a withdrawal), share_capital_transfer_count, share_capital_transfers (savings moved to share capital, never a withdrawal), other_movements, net_movement, closing_balance, new_accounts, open_accounts, time_deposit_count and time_deposits (outstanding at `date_to`), time_deposits_placed_count, time_deposits_placed, time_deposit_interest, interest_expense and total_deposits. Every active branch or product is listed; `totals` are worked out over the whole filter, and the rows add up to them.',
        tags: ['Savings Reports'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'group_by', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['branch', 'product'])), new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The summary'), new OA\Response(response: 403, description: 'Missing reports:view or savings:view, or another branch'), new OA\Response(response: 422, description: 'An invalid filter')],
    )]
    public function summary(SavingsReportRequest $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->summaryFor($request->filters())]);
    }

    #[OA\Get(path: '/api/reports/savings/accounts/export', summary: 'Export the Savings Account Listing as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view')])]
    public function exportAccounts(SavingsExportRequest $request): StreamedResponse
    {
        return $this->csv('savings-accounts.csv', ['Account #', 'Member', 'Member Code', 'Product', 'Branch', 'Status', 'Balance (₱)', 'Opened', 'Closed', 'Dormant Since', 'Last Activity', 'Account Officer'], $this->reports->accountListQuery($request->filters()), function (SavingsAccount $account): array {
            $r = $this->reports->accountListRow($account);

            return [$r['account_number'], $r['borrower_name'], $r['borrower_code'], $r['product_name'], $r['branch_name'], self::inWords(self::ACCOUNT_STATUS_WORDS, $r['status']), $r['balance'], $r['opened_on'], $r['closed_on'], $r['dormant_since'], $r['last_member_activity_on'], $r['account_officer']['name'] ?? ''];
        });
    }

    #[OA\Get(path: '/api/reports/savings/deposits/export', summary: 'Export Savings Deposits as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view')])]
    public function exportDeposits(SavingsExportRequest $request): StreamedResponse
    {
        $filters = $request->filters();

        return $this->flowCsv('savings-deposits.csv', $this->reports->depositsQuery($filters), $filters);
    }

    #[OA\Get(path: '/api/reports/savings/withdrawals/export', summary: 'Export Savings Withdrawals as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view')])]
    public function exportWithdrawals(SavingsExportRequest $request): StreamedResponse
    {
        $filters = $request->filters();

        return $this->flowCsv('savings-withdrawals.csv', $this->reports->withdrawalsQuery($filters), $filters);
    }

    #[OA\Get(path: '/api/reports/savings/large-transactions/export', summary: 'Export Large Savings Transactions as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view')])]
    public function exportLargeTransactions(SavingsExportRequest $request): StreamedResponse
    {
        $filters = $request->filters();

        return $this->flowCsv('large-savings-transactions.csv', $this->reports->largeTransactionsQuery($filters), $filters);
    }

    #[OA\Get(path: '/api/reports/savings/time-deposits/export', summary: 'Export Time Deposits Outstanding as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view')])]
    public function exportTimeDeposits(SavingsExportRequest $request): StreamedResponse
    {
        $filters = $request->filters();

        return $this->csv('time-deposits.csv', ['Certificate #', 'Member', 'Product', 'Branch', 'Placed', 'Maturity', 'Days to Maturity', 'Principal (₱)', 'Rate', 'Days', 'Net Interest (₱)', 'Due at Maturity (₱)', 'Instruction', 'Status'], $this->reports->timeDepositsQuery($filters), function (TimeDeposit $deposit) use ($filters): array {
            $r = $this->reports->timeDepositRow($deposit, $filters);

            return [$r['certificate_number'], $r['borrower_name'], $r['product_name'], $r['branch_name'], $r['placement_date'], $r['maturity_date'], $r['days_to_maturity'], $r['principal'], self::percent($r['annual_rate']), $r['term_days'], $r['net_interest'], $r['maturity_amount'], self::inWords(self::MATURITY_INSTRUCTION_WORDS, $r['maturity_instruction']), self::inWords(self::TIME_DEPOSIT_STATUS_WORDS, $r['status'])];
        });
    }

    #[OA\Get(path: '/api/reports/savings/interest-accrual/export', summary: 'Export Savings Interest Accrual as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view')])]
    public function exportInterestAccrual(SavingsExportRequest $request): StreamedResponse
    {
        $filters = $request->filters();
        $rows = (function () use ($filters) {
            foreach ($this->reports->interestAccrualQuery($filters)->reorder()->lazyById(500)->chunk(500) as $chunk) {
                foreach ($this->reports->interestAccrualRows($chunk->collect()->values()) as $r) {
                    yield [$r['account_number'], $r['borrower_name'], $r['product_name'], $r['branch_name'], self::inWords(self::ACCOUNT_STATUS_WORDS, $r['status']), $r['balance'], $r['accrual_from'], $r['accrual_to'], $r['days'], $r['average_daily_balance'], $r['gross_interest'], $r['tax'], $r['net_interest']];
                }
            }
        })();

        return $this->savingsCsv('savings-interest-accrual.csv', ['Account #', 'Member', 'Product', 'Branch', 'Status', 'Balance (₱)', 'From', 'To', 'Days', 'Average Daily Balance (₱)', 'Gross Interest (₱)', 'Tax (₱)', 'Net Interest (₱)'], $rows);
    }

    #[OA\Get(path: '/api/reports/savings/account-activity/export', summary: 'Export Savings Account Activity as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view')])]
    public function exportAccountActivity(SavingsExportRequest $request): StreamedResponse
    {
        $filters = $request->filters();
        $rows = (function () use ($filters) {
            foreach ($this->reports->accountActivityQuery($filters)->reorder()->lazyById(500)->chunk(500) as $chunk) {
                foreach ($this->reports->accountActivityRows($chunk->collect()->values(), $filters) as $r) {
                    yield [$r['account_number'], $r['borrower_name'], $r['product_name'], $r['branch_name'], self::inWords(self::ACCOUNT_STATUS_WORDS, $r['status']), $r['opening_balance'], $r['credits'], $r['debits'], $r['transaction_count'], $r['closing_balance'], $r['last_transaction_on']];
                }
            }
        })();

        return $this->savingsCsv('savings-account-activity.csv', ['Account #', 'Member', 'Product', 'Branch', 'Status', 'Opening Balance (₱)', 'In (₱)', 'Out (₱)', 'Transactions', 'Closing Balance (₱)', 'Last Transaction'], $rows);
    }

    #[OA\Get(path: '/api/reports/savings/member-statement/{borrower}/export', summary: 'Export a Member Deposit Statement as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'borrower', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view'), new OA\Response(response: 404, description: 'No such member in the caller\'s branches')])]
    public function exportMemberStatement(SavingsExportRequest $request, int $borrower): StreamedResponse
    {
        $filters = $request->filters();
        $member = $this->memberStatements->member($request->user(), $borrower);
        $statement = $this->memberStatements->build($request->user(), $member, $filters);
        $rows = [];

        foreach ($statement['accounts'] as $account) {
            $number = $account['account']['account_number'];
            $rows[] = [$number, $account['date_from'], '', 'Opening balance', '', '', $account['opening_balance']];

            foreach ($account['rows'] as $line) {
                $rows[] = [$number, $line['date'], $line['reference'], self::inWords(self::TRANSACTION_TYPE_WORDS, $line['type']), $line['credit'], $line['debit'], $line['balance_after']];
            }

            $rows[] = [$number, $account['date_to'], '', 'Closing balance', $account['totals']['credit'], $account['totals']['debit'], $account['closing_balance']];
        }

        foreach ($statement['time_deposits'] as $deposit) {
            $rows[] = [$deposit['certificate_number'], $deposit['placement_date'], '', 'Time deposit, '.self::inWords(self::TIME_DEPOSIT_STATUS_WORDS, $deposit['status']), $deposit['principal'], '', ''];
        }

        return $this->savingsCsv('member-deposit-statement.csv', ['Account #', 'Date', 'Reference', 'Type', 'In (₱)', 'Out (₱)', 'Balance (₱)'], $rows);
    }

    #[OA\Get(path: '/api/reports/savings/summary/export', summary: 'Export the Savings Deposit Summary as CSV', tags: ['Savings Reports'], security: [['sanctum' => []]], parameters: [new OA\Parameter(name: 'group_by', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['branch', 'product'])), new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'CSV file'), new OA\Response(response: 403, description: 'Missing reports:export or savings:view')])]
    public function exportSummary(SavingsExportRequest $request): StreamedResponse
    {
        $summary = $this->summaryFor($request->filters());
        $line = fn (string $name, array $r) => [$name, $r['opening_balance'], $r['deposit_count'], $r['deposits'], $r['withdrawal_count'], $r['withdrawals'], $r['interest_credited'], $r['tax_withheld'], $r['fees_charged'], $r['share_capital_transfers'], $r['other_movements'], $r['net_movement'], $r['closing_balance'], $r['new_accounts'], $r['open_accounts'], $r['time_deposit_count'], $r['time_deposits'], $r['time_deposits_placed'], $r['interest_expense'], $r['total_deposits']];
        $rows = array_map(fn (array $r) => $line($r['group_name'], $r), $summary['rows']);
        $rows[] = $line('Total', $summary['totals']);

        return $this->savingsCsv('savings-summary.csv', [
            $summary['group_by'] === DepositFigures::GROUP_PRODUCT ? 'Product' : 'Branch', 'Opening Balance (₱)', 'Deposits (count)', 'Deposits (₱)', 'Withdrawals (count)', 'Withdrawals (₱)',
            'Interest Credited (₱)', 'Tax Withheld (₱)', 'Fees Charged (₱)', 'Share Capital Transfers (₱)', 'Other Movements (₱)', 'Net Movement (₱)', 'Closing Balance (₱)', 'New Accounts', 'Open Accounts',
            'Time Deposits (count)', 'Time Deposits (₱)', 'Time Deposits Placed (₱)', 'Interest Expense (₱)', 'Total Deposits (₱)',
        ], $rows);
    }

    /**
     * @param  Builder<SavingsTransaction>  $query
     * @param  array<string, mixed>  $extra
     */
    private function flowList(Builder $query, array $filters, array $extra = []): JsonResponse
    {
        $page = (clone $query)->paginate($this->perPage($filters));
        $asAt = $this->reports->period($filters)[1];

        return $this->list($page, $page->getCollection()->map(fn (SavingsTransaction $row) => $this->reports->flowRow($row, $asAt))->all(), $this->reports->flowTotals($query, $asAt), [...$this->range($filters), ...$extra]);
    }

    /** @param  Builder<SavingsTransaction>  $query */
    private function flowCsv(string $filename, Builder $query, array $filters): StreamedResponse
    {
        $asAt = $this->reports->period($filters)[1];

        return $this->csv($filename, ['Date', 'Reference', 'Account #', 'Member', 'Product', 'Branch', 'Type', 'Method', 'Amount (₱)', 'Balance (₱)', 'Reversed By'], $query, function (SavingsTransaction $row) use ($asAt): array {
            $r = $this->reports->flowRow($row, $asAt);

            return [$r['date'], $r['reference'], $r['account_number'], $r['borrower_name'], $r['product_name'], $r['branch_name'], self::inWords(self::TRANSACTION_TYPE_WORDS, $r['type']), self::inWords(self::METHOD_WORDS, $r['method']), $r['amount'], $r['balance_after'], $r['reversed_by_reference'] ?? ''];
        });
    }

    /** @return array<string, mixed> */
    private function summaryFor(array $filters): array
    {
        [$from, $to] = $this->reports->period($filters);
        $scope = ['branch_ids' => $filters['branch_ids'], 'branch_id' => isset($filters['branch_id']) ? (int) $filters['branch_id'] : null, 'product_id' => isset($filters['product_id']) ? (int) $filters['product_id'] : null];

        return $this->summary->build($scope, $from, $to, $filters['group_by'] ?? DepositFigures::GROUP_BRANCH);
    }

    /**
     * A key in words for a CSV cell; empty when there is none (a transaction
     * with no method), "Other" for a key with no words.
     *
     * @param  array<string, string>  $words
     */
    private static function inWords(array $words, ?string $key): string
    {
        return $key === null || $key === '' ? '' : ($words[$key] ?? 'Other');
    }

    /**
     * A stored rate as the screen shows it, "3.5%" for "3.5000": trailing
     * zeros dropped, never rounded.
     */
    private static function percent(?string $rate): string
    {
        if ($rate === null || $rate === '') {
            return '';
        }

        return (str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate).'%';
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
     * Stream a savings CSV (review L4). Cells are guarded against formulas as
     * CsvExportTrait does, except a plain signed number ("-500.00"), which a
     * spreadsheet must read as the number it is.
     *
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    private function savingsCsv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(
                    fn (mixed $cell) => is_string($cell) && preg_match('/\A-?\d+(\.\d+)?\z/', $cell) === 1 ? $cell : self::sanitizeCsvCell($cell),
                    is_array($row) ? $row : iterator_to_array($row),
                ));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  list<string>  $headers
     * @param  Closure(mixed): array<int, mixed>  $map
     */
    private function csv(string $filename, array $headers, Builder $query, Closure $map): StreamedResponse
    {
        return $this->savingsCsv($filename, $headers, $query->lazy(500)->map($map));
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
