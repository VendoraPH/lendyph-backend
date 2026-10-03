<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\ShareCapitalLedger;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The report date filters select whole days, inclusive at both ends.
 *
 * For a DATETIME/TIMESTAMP column (`loans.released_at`, `borrowers.created_at`)
 * that means a row stamped 00:00:00 on `date_from` and 23:59:59 on `date_to`
 * is in, and 23:59:59 the day before and 00:00:00 the day after are out. For a
 * DATE column (`repayments.payment_date`, `share_capital_ledger.date`) the day
 * before `date_from` and the day after `date_to` are out. These are the rows
 * whereDate() selected, pinned per report so the filters can be written as
 * plain range comparisons an index can serve without changing a figure.
 *
 * Every row lives in a branch of its own and every request is scoped to it,
 * so nothing seeded elsewhere moves the expected figures.
 */
class ReportDateBoundaryTest extends TestCase
{
    use SetupLendyPH;

    private const PERIOD = 'date_from=2026-03-10&date_to=2026-03-20';

    private Branch $reportBranch;

    private LoanProduct $product;

    private Borrower $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->reportBranch = Branch::factory()->create();
        $this->product = LoanProduct::factory()->create(['processing_fee' => 2.0]);
        $this->member = $this->borrower('2025-01-01 08:00:00');
    }

    // ── loans.released_at (TIMESTAMP) ────────────────────────────────────

    /**
     * Four loans around the period's edges; only 2,000 and 4,000 are in it.
     */
    private function releaseAroundThePeriod(): void
    {
        $this->releasedLoan('2026-03-09 23:59:59', 1000);
        $this->releasedLoan('2026-03-10 00:00:00', 2000);
        $this->releasedLoan('2026-03-20 23:59:59', 4000);
        $this->releasedLoan('2026-03-21 00:00:00', 8000);
    }

    public function test_releases_list_and_borrowers_released_take_whole_days_of_released_at(): void
    {
        $this->releaseAroundThePeriod();

        $releases = $this->report('releases')->assertOk();
        $this->assertSame([4000.0, 2000.0], array_map('floatval', array_column($releases->json('data'), 'principal_amount')));
        $this->assertSame(2, $releases->json('totals.count'));
        $this->assertEquals(6000, $releases->json('totals.total_principal'));

        $borrowers = $this->report('borrowers/released')->assertOk();
        $this->assertSame(2, $borrowers->json('totals.loan_count'));
        $this->assertEquals(6000, $borrowers->json('totals.total_principal'));
    }

    public function test_loan_balance_summary_and_its_branch_rows_take_whole_days_of_released_at(): void
    {
        $this->releaseAroundThePeriod();

        $summary = $this->report('loan-balance-summary')->assertOk();
        $this->assertSame(2, $summary->json('data.portfolio.loan_count'));
        $this->assertEquals(6000, $summary->json('data.portfolio.total_released'));

        $branchRow = collect($summary->json('data.by_branch'))->firstWhere('branch_id', $this->reportBranch->id);
        $this->assertSame(2, $branchRow['loan_count']);
        $this->assertEquals(6000, $branchRow['total_released']);
    }

    public function test_income_processing_fees_take_whole_days_of_released_at(): void
    {
        $this->releaseAroundThePeriod();

        // 2% of the 6,000 released inside the period.
        $this->assertEquals(120, $this->report('income')->assertOk()->json('data.processing_fees'));
    }

    public function test_disbursements_take_whole_days_of_released_at(): void
    {
        $this->releaseAroundThePeriod();

        $data = $this->report('disbursements')->assertOk()->json('data');
        $this->assertSame(2, $data['loans_released']);
        $this->assertEquals(6000, $data['total_disbursed']);
    }

    public function test_cash_flow_and_its_branch_rows_take_whole_days_of_released_at(): void
    {
        $this->releaseAroundThePeriod();

        $data = $this->report('cash-flow')->assertOk()->json('data');
        $this->assertSame(2, $data['outflows']['releases']['count']);
        $this->assertEquals(6000, $data['outflows']['releases']['net_proceeds']);
        $this->assertEquals(6000, $data['non_cash']['principal_released']);

        $branchRow = collect($data['by_branch'])->firstWhere('branch_id', $this->reportBranch->id);
        $this->assertSame(2, $branchRow['release_count']);
        $this->assertEquals(6000, $branchRow['outflow_net_proceeds']);
    }

    public function test_portfolio_by_product_takes_whole_days_of_released_at(): void
    {
        $this->releaseAroundThePeriod();

        $row = collect($this->report('portfolio-by-product')->assertOk()->json('data.products'))
            ->firstWhere('product_id', $this->product->id);

        $this->assertNotNull($row, 'the product has no row');
        $this->assertSame(2, $row['loan_count']);
        $this->assertEquals(6000, $row['total_released']);
    }

    // ── borrowers.created_at (TIMESTAMP) ─────────────────────────────────

    public function test_borrower_report_counts_whole_days_of_created_at(): void
    {
        $this->borrower('2026-03-09 23:59:59');
        $this->borrower('2026-03-10 00:00:00');
        $this->borrower('2026-03-20 23:59:59');
        $this->borrower('2026-03-21 00:00:00');

        $this->assertSame(2, $this->report('borrowers')->assertOk()->json('data.new_borrowers'));
    }

    // ── repayments.payment_date (DATE) ───────────────────────────────────

    /**
     * Four receipts around the period's edges; only 200 and 400 of interest
     * are in it.
     */
    private function collectAroundThePeriod(): Loan
    {
        $loan = $this->releasedLoan('2026-01-05 09:00:00', 50000);

        $this->repayment($loan, '2026-03-09', 100);
        $this->repayment($loan, '2026-03-10', 200);
        $this->repayment($loan, '2026-03-20', 400);
        $this->repayment($loan, '2026-03-21', 800);

        return $loan;
    }

    public function test_repayments_list_takes_the_period_s_payment_dates(): void
    {
        $this->collectAroundThePeriod();

        $response = $this->report('repayments')->assertOk();
        $this->assertSame(2, $response->json('totals.count'));
        $this->assertEquals(600, $response->json('totals.total_amount_paid'));
        $this->assertSame(['2026-03-20', '2026-03-10'], array_map(
            fn (string $date) => substr($date, 0, 10),
            array_column($response->json('data'), 'payment_date'),
        ));
    }

    public function test_income_and_income_by_loan_take_the_period_s_payment_dates(): void
    {
        $this->collectAroundThePeriod();

        $this->assertEquals(600, $this->report('income')->assertOk()->json('data.interest_income'));

        $byLoan = $this->report('income/by-loan')->assertOk();
        $this->assertSame(2, $byLoan->json('totals.payments'));
        $this->assertEquals(600, $byLoan->json('totals.interest_income'));
    }

    public function test_performance_collected_takes_the_period_s_payment_dates(): void
    {
        $this->collectAroundThePeriod();

        $row = collect($this->report('performance')->assertOk()->json('data.by_branch'))
            ->firstWhere('branch_id', $this->reportBranch->id);

        $this->assertEquals(600, $row['collected']);
        $this->assertSame(2, $row['payment_count']);
    }

    // ── share_capital_ledger.date (DATE) ─────────────────────────────────

    /**
     * Credits of 100 the day before, 200 and 400 inside, 800 the day after.
     */
    private function contributeAroundThePeriod(): void
    {
        foreach (['2026-03-09' => 100, '2026-03-10' => 200, '2026-03-20' => 400, '2026-03-21' => 800] as $date => $credit) {
            ShareCapitalLedger::factory()->create([
                'borrower_id' => $this->member->id,
                'date' => $date,
                'credit' => $credit,
                'debit' => 0,
            ]);
        }
    }

    public function test_share_capital_report_splits_opening_and_period_on_the_ledger_date(): void
    {
        $this->contributeAroundThePeriod();

        $data = $this->report('share-capital')->assertOk()->json('data');

        $this->assertEquals(100, $data['opening_balance']);
        $this->assertEquals(600, $data['credits']);
        $this->assertSame(2, $data['entry_count']);
        $this->assertEquals(700, $data['closing_balance']);

        $march = collect($data['by_month'])->firstWhere('period', '2026-03');
        $this->assertEquals(600, $march['credits']);
        $this->assertEquals(700, $march['closing_balance']);

        $member = collect($data['by_member'])->firstWhere('borrower_id', $this->member->id);
        $this->assertEquals(100, $member['opening_balance']);
        $this->assertEquals(600, $member['credits']);
        $this->assertEquals(700, $member['closing_balance']);
    }

    public function test_share_capital_statement_splits_opening_and_period_on_the_ledger_date(): void
    {
        $this->contributeAroundThePeriod();

        $data = $this->getJson("/api/reports/share-capital-statement/{$this->member->id}?".self::PERIOD)
            ->assertOk()
            ->json('data');

        $this->assertEquals(100, $data['opening_balance']);
        $this->assertSame(['2026-03-10', '2026-03-20'], array_column($data['entries'], 'date'));
        $this->assertEquals(600, $data['totals']['credits']);
        $this->assertEquals(700, $data['closing_balance']);
    }

    private function report(string $path)
    {
        return $this->getJson("/api/reports/{$path}?".self::PERIOD."&branch_id={$this->reportBranch->id}&per_page=100");
    }

    private function borrower(string $createdAt): Borrower
    {
        return Borrower::factory()->create([
            'branch_id' => $this->reportBranch->id,
            'created_at' => $createdAt,
        ]);
    }

    private function releasedLoan(string $releasedAt, float $principal): Loan
    {
        return Loan::factory()->create([
            'borrower_id' => $this->member->id,
            'loan_product_id' => $this->product->id,
            'branch_id' => $this->reportBranch->id,
            'created_by' => $this->admin->id,
            'principal_amount' => $principal,
            'net_proceeds' => $principal,
            'total_deductions' => 0,
            // No recorded deductions, so the Income report charges the
            // product's rate on the principal.
            'deductions' => null,
            'status' => 'ongoing',
            'released_at' => $releasedAt,
        ]);
    }

    private function repayment(Loan $loan, string $date, float $interest): Repayment
    {
        return Repayment::factory()->create([
            'loan_id' => $loan->id,
            'payment_date' => $date,
            'amount_paid' => $interest,
            'principal_applied' => 0,
            'interest_applied' => $interest,
            'penalty_applied' => 0,
            'status' => 'posted',
            'received_by' => $this->admin->id,
        ]);
    }
}
