<?php

namespace Tests\Feature\Accounting\ColumnBooks;

use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\Accounting\JournalPoster;
use App\Services\LoanService;
use App\Services\RepaymentService;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The cash receipts and cash disbursements books in columns.
 *
 * The same journals as the line books (BookBuilder::movedMoney()), one row per
 * journal: the money accounts on the left, one column per mapped role on the
 * book's own side, and everything else in Sundry with its own side. Every row
 * and the totals balance; the browser adds nothing up.
 */
class ColumnBooksTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const RECEIPT_ROLES = [
        'loans_receivable', 'interest_income', 'penalty_income', 'insurance_premium_payable',
        'share_capital', 'borrower_advances', 'gcash_service_income',
    ];

    private const DISBURSEMENT_ROLES = ['loans_receivable', 'accounts_payable', 'share_capital', 'borrower_advances'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** @return array<string, mixed> */
    private function columns(string $book, string $from = '2026-09-01', string $to = '2026-09-30', string $query = ''): array
    {
        return $this->getJson("/api/accounting/books/{$book}/columns?from={$from}&to={$to}{$query}")
            ->assertOk()
            ->json('data');
    }

    /**
     * @param  array<string, int>  $credits  code => centavos
     * @param  array<string, mixed>  $attributes
     */
    private function receipt(string $moneyCode, array $credits, array $attributes = []): AccountingJournal
    {
        $lines = [['account_id' => $this->account($moneyCode), 'debit' => array_sum($credits), 'credit' => 0]];

        foreach ($credits as $code => $centavos) {
            $lines[] = ['account_id' => $this->account((string) $code), 'debit' => 0, 'credit' => $centavos];
        }

        return $this->postJournal($lines, array_merge(['date' => '2026-09-10'], $attributes));
    }

    /** @param  array<string, mixed>  $row */
    private function assertRowBalances(array $row, string $kind): void
    {
        $sundryDebit = array_sum(array_column($row['sundry'], 'debit'));
        $sundryCredit = array_sum(array_column($row['sundry'], 'credit'));
        $sundry = $kind === 'cash_receipts' ? $sundryCredit - $sundryDebit : $sundryDebit - $sundryCredit;

        $this->assertSame(
            array_sum($row['money']),
            array_sum($row['columns']) + $sundry,
            "Row {$row['journal_no']} does not balance",
        );
    }

    /** @param  array<string, mixed>  $book */
    private function assertBookBalances(array $book): void
    {
        foreach ($book['rows'] as $row) {
            $this->assertRowBalances($row, $book['kind']);
        }

        $totals = $book['totals'];
        $sundry = $book['kind'] === 'cash_receipts'
            ? $totals['sundry_credit'] - $totals['sundry_debit']
            : $totals['sundry_debit'] - $totals['sundry_credit'];

        $this->assertSame(array_sum($totals['money']), array_sum($totals['columns']) + $sundry);
        $this->assertSame($totals['total_debit'], $totals['total_credit']);
        $this->assertTrue($book['is_balanced']);
    }

    public function test_a_collection_fills_every_receipts_column_on_one_row(): void
    {
        $this->seedChartOfAccounts();

        $journal = $this->receipt('1010', [
            '1110' => 500000,
            '4010' => 150000,
            '4020' => 20000,
            '2040' => 30000,
            '3060' => 50000,
            '2300' => 1234,
        ], ['source' => 'loan_collection', 'reference' => 'OR-000123', 'description' => 'Loan collection LN-000001']);

        $book = $this->columns('cash-receipts');

        $this->assertSame(
            ['kind', 'from', 'to', 'money_columns', 'columns', 'rows', 'totals', 'is_balanced'],
            array_keys($book),
        );
        $this->assertSame('cash_receipts', $book['kind']);
        $this->assertSame('2026-09-01', $book['from']);
        $this->assertSame('2026-09-30', $book['to']);

        $this->assertSame([
            ['account_id' => $this->account('1010'), 'account_code' => '1010', 'account_name' => 'Cash on Hand'],
        ], $book['money_columns']);

        $this->assertSame(self::RECEIPT_ROLES, array_column($book['columns'], 'key'));
        $this->assertSame([
            'Current Loans Receivable', 'Interest Income', 'Penalty Income', 'Insurance Premium Payable',
            'Share Capital', 'Other Liabilities', 'GCash Service Income',
        ], array_column($book['columns'], 'label'));

        $this->assertCount(1, $book['rows']);
        $row = $book['rows'][0];
        $this->assertSame(
            ['journal_id', 'journal_no', 'date', 'reference', 'particulars', 'money', 'columns', 'sundry'],
            array_keys($row),
        );
        $this->assertSame($journal->id, $row['journal_id']);
        $this->assertSame($journal->fresh()->journal_no, $row['journal_no']);
        $this->assertSame('2026-09-10', $row['date']);
        $this->assertSame('OR-000123', $row['reference']);
        $this->assertSame('Loan collection LN-000001', $row['particulars']);
        $this->assertSame([$this->account('1010') => 751234], $row['money']);
        $this->assertSame([
            'loans_receivable' => 500000,
            'interest_income' => 150000,
            'penalty_income' => 20000,
            'insurance_premium_payable' => 30000,
            'share_capital' => 50000,
            'borrower_advances' => 1234,
        ], $row['columns']);
        $this->assertSame([], $row['sundry']);

        $this->assertSame([
            'money' => [$this->account('1010') => 751234],
            'columns' => [
                'loans_receivable' => 500000,
                'interest_income' => 150000,
                'penalty_income' => 20000,
                'insurance_premium_payable' => 30000,
                'share_capital' => 50000,
                'borrower_advances' => 1234,
                'gcash_service_income' => 0,
            ],
            'sundry_debit' => 0,
            'sundry_credit' => 0,
            'total_debit' => 751234,
            'total_credit' => 751234,
        ], $book['totals']);
        $this->assertBookBalances($book);
    }

    public function test_a_gcash_cash_in_fills_the_money_columns_and_the_service_income_column(): void
    {
        $this->seedChartOfAccounts();

        // Cash In: Dr 1010 amount + charge / Cr 1020 amount / Cr 4090 charge.
        $this->postJournal([
            ['account_id' => $this->account('1010'), 'debit' => 101500, 'credit' => 0],
            ['account_id' => $this->account('1020'), 'debit' => 0, 'credit' => 100000],
            ['account_id' => $this->account('4090'), 'debit' => 0, 'credit' => 1500],
        ], ['date' => '2026-09-12', 'source' => 'cash_in', 'description' => 'GCash Cash In']);

        $book = $this->columns('cash-receipts');

        $this->assertSame(['1010', '1020'], array_column($book['money_columns'], 'account_code'));
        $this->assertCount(1, $book['rows']);
        $this->assertSame([
            $this->account('1010') => 101500,
            $this->account('1020') => -100000,
        ], $book['rows'][0]['money']);
        $this->assertSame(['gcash_service_income' => 1500], $book['rows'][0]['columns']);
        $this->assertSame([], $book['rows'][0]['sundry']);
        $this->assertSame(1500, $book['totals']['columns']['gcash_service_income']);
        $this->assertBookBalances($book);
    }

    public function test_disbursements_put_a_release_payable_and_expense_in_their_places(): void
    {
        $this->seedChartOfAccounts();

        // A release: the gross out as receivable, the net in cash, deductions withheld.
        $release = $this->postJournal([
            ['account_id' => $this->account('1110'), 'debit' => 1000000, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 980000],
            ['account_id' => $this->account('4030'), 'debit' => 0, 'credit' => 15000],
            ['account_id' => $this->account('2040'), 'debit' => 0, 'credit' => 5000],
        ], ['date' => '2026-09-05', 'source' => 'loan_release', 'description' => 'Loan release']);
        $payable = $this->postSimpleJournal('2010', '1040', 50000, ['date' => '2026-09-06', 'description' => 'Supplier paid']);
        $expense = $this->postSimpleJournal('5030', '1010', 20000, ['date' => '2026-09-07', 'description' => 'Office supplies']);

        $book = $this->columns('cash-disbursements');

        $this->assertSame('cash_disbursements', $book['kind']);
        $this->assertSame(self::DISBURSEMENT_ROLES, array_column($book['columns'], 'key'));
        $this->assertSame(['1010', '1040'], array_column($book['money_columns'], 'account_code'));
        $this->assertSame([$release->id, $payable->id, $expense->id], array_column($book['rows'], 'journal_id'));

        [$releaseRow, $payableRow, $expenseRow] = $book['rows'];

        $this->assertSame([$this->account('1010') => 980000], $releaseRow['money']);
        $this->assertSame(['loans_receivable' => 1000000], $releaseRow['columns']);
        $this->assertSame([
            ['account_code' => '4030', 'account_name' => 'Loan Processing Fee Income', 'debit' => 0, 'credit' => 15000],
            ['account_code' => '2040', 'account_name' => 'Insurance Premium Payable', 'debit' => 0, 'credit' => 5000],
        ], $releaseRow['sundry']);

        $this->assertSame([$this->account('1040') => 50000], $payableRow['money']);
        $this->assertSame(['accounts_payable' => 50000], $payableRow['columns']);
        $this->assertSame([], $payableRow['sundry']);

        $this->assertSame([$this->account('1010') => 20000], $expenseRow['money']);
        $this->assertSame([], $expenseRow['columns']);
        $this->assertSame('5030', $expenseRow['sundry'][0]['account_code']);
        $this->assertSame(20000, $expenseRow['sundry'][0]['debit']);
        $this->assertSame(0, $expenseRow['sundry'][0]['credit']);

        $this->assertSame([
            $this->account('1010') => 1000000,
            $this->account('1040') => 50000,
        ], $book['totals']['money']);
        $this->assertSame([
            'loans_receivable' => 1000000,
            'accounts_payable' => 50000,
            'share_capital' => 0,
            'borrower_advances' => 0,
        ], $book['totals']['columns']);
        $this->assertSame(20000, $book['totals']['sundry_debit']);
        $this->assertSame(20000, $book['totals']['sundry_credit']);
        $this->assertSame(1070000, $book['totals']['total_debit']);
        $this->assertBookBalances($book);
    }

    public function test_a_column_account_on_the_other_side_goes_to_sundry(): void
    {
        $this->seedChartOfAccounts();

        // Restructure J1: Dr 1110 P / Dr 1010 D / Cr 1110 carried / Cr 1210 capitalised / Cr 4030 fee.
        $this->postJournal([
            ['account_id' => $this->account('1110'), 'debit' => 600000, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 12000, 'credit' => 0],
            ['account_id' => $this->account('1110'), 'debit' => 0, 'credit' => 550000],
            ['account_id' => $this->account('1210'), 'debit' => 0, 'credit' => 50000],
            ['account_id' => $this->account('4030'), 'debit' => 0, 'credit' => 12000],
        ], ['date' => '2026-09-15', 'source' => 'loan_release', 'description' => 'Restructure']);

        $row = $this->columns('cash-receipts')['rows'][0];

        $this->assertSame([$this->account('1010') => 12000], $row['money']);
        $this->assertSame(['loans_receivable' => 550000], $row['columns']);
        $this->assertSame([
            ['account_code' => '1110', 'account_name' => 'Current Loans Receivable', 'debit' => 600000, 'credit' => 0],
            ['account_code' => '1210', 'account_name' => 'Unearned Interest and Penalty – Restructured Loans', 'debit' => 0, 'credit' => 50000],
            ['account_code' => '4030', 'account_name' => 'Loan Processing Fee Income', 'debit' => 0, 'credit' => 12000],
        ], $row['sundry']);
        $this->assertRowBalances($row, 'cash_receipts');
    }

    public function test_past_due_loans_receivable_is_not_the_loans_receivable_column(): void
    {
        $this->seedChartOfAccounts();

        $this->receipt('1010', ['1120' => 40000]);

        $row = $this->columns('cash-receipts')['rows'][0];

        $this->assertSame([], $row['columns']);
        $this->assertSame('1120', $row['sundry'][0]['account_code']);
        $this->assertSame(40000, $row['sundry'][0]['credit']);
    }

    public function test_an_unmapped_role_has_no_column_and_its_lines_go_to_sundry(): void
    {
        $this->seedChartOfAccounts();
        AccountingAccountMapping::query()->where('role', 'penalty_income')->delete();

        $this->receipt('1010', ['1110' => 10000, '4020' => 2500]);

        $book = $this->columns('cash-receipts');

        $this->assertNotContains('penalty_income', array_column($book['columns'], 'key'));
        $this->assertArrayNotHasKey('penalty_income', $book['totals']['columns']);
        $this->assertSame(['loans_receivable' => 10000], $book['rows'][0]['columns']);
        $this->assertSame([
            ['account_code' => '4020', 'account_name' => 'Penalty Income', 'debit' => 0, 'credit' => 2500],
        ], $book['rows'][0]['sundry']);
        $this->assertSame(2500, $book['totals']['sundry_credit']);
        $this->assertBookBalances($book);
    }

    public function test_two_roles_on_one_account_fill_one_column(): void
    {
        $this->seedChartOfAccounts();
        AccountingAccountMapping::query()
            ->where('role', 'borrower_advances')
            ->update(['accounting_account_id' => $this->account('3060')]);

        $this->receipt('1010', ['3060' => 7000]);

        $book = $this->columns('cash-receipts');

        $this->assertContains('share_capital', array_column($book['columns'], 'key'));
        $this->assertNotContains('borrower_advances', array_column($book['columns'], 'key'));
        $this->assertSame(['share_capital' => 7000], $book['rows'][0]['columns']);
        $this->assertBookBalances($book);
    }

    public function test_the_four_line_books_keep_their_shape(): void
    {
        $this->seedChartOfAccounts();
        $this->receipt('1010', ['1110' => 10000, '4010' => 2000]);
        $this->postSimpleJournal('5030', '1010', 3000);

        foreach (['general-journal', 'general-ledger', 'cash-receipts', 'cash-disbursements'] as $path) {
            $book = $this->getJson("/api/accounting/books/{$path}?from=2026-09-01&to=2026-09-30")->assertOk()->json('data');

            $this->assertSame(['kind', 'from', 'to', 'rows', 'total_debit', 'total_credit'], array_keys($book), $path);
            $this->assertSame(
                ['date', 'journal_no', 'reference', 'particulars', 'account_code', 'account_name', 'debit', 'credit'],
                array_keys($book['rows'][0]),
                $path,
            );
        }
    }

    public function test_the_rows_are_the_line_books_journals_and_a_reversal_lands_in_the_opposite_book(): void
    {
        $this->seedChartOfAccounts();

        $collection = $this->receipt('1010', ['1110' => 80000, '4010' => 20000], ['date' => '2026-09-08']);
        $this->receipt('1040', ['4010' => 5000], ['date' => '2026-09-03']);
        $this->postSimpleJournal('1040', '1010', 30000, ['date' => '2026-09-09']);
        $this->postSimpleJournal('5030', '1010', 7000, ['date' => '2026-09-04']);
        $reversal = app(JournalPoster::class)->reverse($collection, '2026-09-20', 'Wrong loan', $this->admin->id);

        foreach (['cash-receipts', 'cash-disbursements'] as $path) {
            $lines = $this->getJson("/api/accounting/books/{$path}?from=2026-09-01&to=2026-09-30")->assertOk()->json('data');
            $book = $this->columns($path);

            $this->assertSame(
                array_values(array_unique(array_column($lines['rows'], 'journal_no'))),
                array_column($book['rows'], 'journal_no'),
                "{$path}: the column book lists different journals",
            );
            $this->assertSame($lines['total_debit'], $book['totals']['total_debit']);
            $this->assertSame($lines['total_credit'], $book['totals']['total_credit']);
            $this->assertBookBalances($book);
        }

        $disbursements = $this->columns('cash-disbursements');
        $reversed = collect($disbursements['rows'])->firstWhere('journal_id', $reversal->id);
        $this->assertNotNull($reversed);
        $this->assertSame([$this->account('1010') => 100000], $reversed['money']);
        $this->assertSame(['loans_receivable' => 80000], $reversed['columns']);
        $this->assertSame(20000, $reversed['sundry'][0]['debit']);

        $receipts = $this->columns('cash-receipts');
        $this->assertContains($collection->id, array_column($receipts['rows'], 'journal_id'));
        // Dated, then by journal number.
        $this->assertSame(['2026-09-03', '2026-09-08'], array_column($receipts['rows'], 'date'));
    }

    public function test_a_real_release_and_collection_land_in_the_column_books(): void
    {
        $this->seedChartOfAccounts();

        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'processing_fee' => 2,
            'service_fee' => 1,
            'notarial_fee' => 0.5,
        ]);
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $loans = app(LoanService::class);
        $loan = $loans->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 50000.00,
            'start_date' => now()->toDateString(),
        ], $this->admin);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');
        $loans->release($loan, $this->admin, [
            'insurance_premium_percentage' => 0.6,
            'insurance_premium_amount' => 300.00,
            'insurance_payment_type' => 'full',
        ]);
        app(RepaymentService::class)->processRepayment($loan->fresh(), 5000, now()->toDateString(), $this->admin);

        $today = now()->toDateString();
        $from = now()->subDays(3)->toDateString();

        $disbursements = $this->columns('cash-disbursements', $from, $today);
        $this->assertCount(1, $disbursements['rows']);
        $this->assertSame(['loans_receivable' => 5000000], $disbursements['rows'][0]['columns']);
        $this->assertNotEmpty($disbursements['rows'][0]['sundry']);
        $this->assertBookBalances($disbursements);

        $receipts = $this->columns('cash-receipts', $from, $today);
        $this->assertCount(1, $receipts['rows']);
        $this->assertSame([$this->account('1010') => 500000], $receipts['rows'][0]['money']);
        $this->assertSame(500000, array_sum($receipts['rows'][0]['columns']));
        $this->assertBookBalances($receipts);

        foreach (['cash-receipts', 'cash-disbursements'] as $path) {
            $lines = $this->getJson("/api/accounting/books/{$path}?from={$from}&to={$today}")->assertOk()->json('data');
            $this->assertSame(
                array_values(array_unique(array_column($lines['rows'], 'journal_no'))),
                array_column($this->columns($path, $from, $today)['rows'], 'journal_no'),
            );
        }
    }

    public function test_drafts_and_transfers_are_in_neither_column_book(): void
    {
        $this->seedChartOfAccounts();

        $this->draftJournal([
            ['account_id' => $this->account('1010'), 'debit' => 99900, 'credit' => 0],
            ['account_id' => $this->account('4010'), 'debit' => 0, 'credit' => 99900],
        ]);
        $this->postSimpleJournal('1040', '1010', 30000);

        $this->assertSame([], $this->columns('cash-receipts')['rows']);
        $this->assertSame([], $this->columns('cash-disbursements')['rows']);
    }

    public function test_with_no_chart_both_books_are_empty_with_every_total_zero(): void
    {
        foreach (['cash-receipts' => 'cash_receipts', 'cash-disbursements' => 'cash_disbursements'] as $path => $kind) {
            $response = $this->getJson("/api/accounting/books/{$path}/columns?from=2026-09-01&to=2026-09-30")
                ->assertOk()
                ->assertExactJson(['data' => [
                    'kind' => $kind,
                    'from' => '2026-09-01',
                    'to' => '2026-09-30',
                    'money_columns' => [],
                    'columns' => [],
                    'rows' => [],
                    'totals' => [
                        'money' => [],
                        'columns' => [],
                        'sundry_debit' => 0,
                        'sundry_credit' => 0,
                        'total_debit' => 0,
                        'total_credit' => 0,
                    ],
                    'is_balanced' => true,
                ]]);

            // Maps, so the empty ones are JSON objects, never lists.
            $this->assertStringContainsString('"totals":{"money":{},"columns":{}', $response->getContent());
        }
    }

    public function test_empty_maps_are_json_objects(): void
    {
        $this->seedChartOfAccounts();
        $this->postSimpleJournal('5030', '1010', 20000);

        $json = $this->getJson('/api/accounting/books/cash-disbursements/columns?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"columns":{}', $json);
    }

    public function test_the_period_is_validated_like_the_other_books(): void
    {
        foreach (['cash-receipts', 'cash-disbursements'] as $path) {
            $this->getJson("/api/accounting/books/{$path}/columns?from=2026-01-01&to=2027-01-02")
                ->assertStatus(422)
                ->assertJsonValidationErrors('from');
            $this->getJson("/api/accounting/books/{$path}/columns?from=2026-09-30&to=2026-09-01")
                ->assertStatus(422)
                ->assertJsonValidationErrors('to');
            $this->getJson("/api/accounting/books/{$path}/columns")
                ->assertStatus(422)
                ->assertJsonValidationErrors(['from', 'to']);
            $this->getJson("/api/accounting/books/{$path}/columns?from=2026-01-01&to=2026-12-31")
                ->assertOk();
        }
    }

    public function test_both_column_books_require_accounting_view(): void
    {
        $this->actingAs($this->userWithNoRole());

        $this->getJson('/api/accounting/books/cash-receipts/columns?from=2026-09-01&to=2026-09-30')->assertForbidden();
        $this->getJson('/api/accounting/books/cash-disbursements/columns?from=2026-09-01&to=2026-09-30')->assertForbidden();
    }

    public function test_a_branch_user_sees_only_their_branches_and_unbranched_journals_need_view_all(): void
    {
        $this->seedChartOfAccounts();
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);

        $here = $this->receipt('1010', ['4010' => 10000], ['branch_id' => $this->branch->id]);
        $there = $this->receipt('1010', ['4010' => 20000], ['branch_id' => $far->id]);
        $unbranched = $this->receipt('1010', ['4010' => 40000], ['branch_id' => null]);
        $this->postSimpleJournal('5030', '1010', 3000, ['branch_id' => $far->id]);

        $role = Role::firstOrCreate(['name' => 'column_book_reader', 'guard_name' => 'web']);
        $role->syncPermissions(['accounting:view']);
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);

        foreach (['cash-receipts', 'cash-disbursements'] as $path) {
            $expected = $this->columns($path, query: '&branch_id='.$this->branch->id);

            $this->actingAs($user);
            $this->assertSame($expected, $this->columns($path));
            $this->getJson("/api/accounting/books/{$path}/columns?from=2026-09-01&to=2026-09-30&branch_id={$far->id}")
                ->assertForbidden();
            $this->actingAs($this->admin);
        }

        $this->actingAs($user);
        $this->assertSame([$here->id], array_column($this->columns('cash-receipts')['rows'], 'journal_id'));
        $this->assertSame([], $this->columns('cash-disbursements')['rows']);

        $this->actingAs($this->admin);
        $this->assertSame(
            [$here->id, $there->id, $unbranched->id],
            array_column($this->columns('cash-receipts')['rows'], 'journal_id'),
        );
    }
}
