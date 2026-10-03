<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The remaining reports send every total their screen shows, so the browser
 * never adds up money: the Due/Past Due and Statement of Account "Paid"
 * figures, and the totals of the Portfolio Summary, Statement of Account,
 * Subsidiary Ledger, Cash Flow, Performance and Share Capital reports. Each
 * total is checked against the server figure it must equal, and the reports
 * that already sent theirs (Collection Efficiency, Portfolio by Product,
 * Provisioning) are held to always sending them.
 */
class ReportTotalsFromServerTest extends TestCase
{
    use SetupLendyPH;

    private Branch $north;

    private Branch $south;

    private LoanProduct $product;

    private int $period = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->north = Branch::factory()->create();
        $this->south = Branch::factory()->create();
        $this->product = LoanProduct::factory()->create();
    }

    public function test_due_past_due_rows_carry_what_was_paid_and_add_up_to_the_total(): void
    {
        $loan = $this->loan($this->north, 10000);
        $this->schedule($loan, now()->subDays(20)->toDateString(), 1000.00, 100.00, 250.25, 100.00, 10.10, 'partial');
        $this->schedule($loan, now()->subDays(10)->toDateString(), 2000.50, 200.05, 0.01, 0, 0, 'partial');

        $response = $this->getJson("/api/reports/due-past-due?branch_id={$this->north->id}&per_page=50")->assertOk();

        $this->assertSame([350.25, 0.01], array_map('floatval', array_column($response->json('data'), 'amount_paid')));
        $this->assertEquals(350.26, $response->json('totals.total_paid'));
    }

    public function test_the_statement_of_account_totals_its_schedule_and_its_transactions(): void
    {
        $loan = $this->loan($this->north, 10000);
        $this->schedule($loan, '2026-08-01', 5000.00, 150.10, 5000.00, 150.10, 20.00, 'paid');
        $this->schedule($loan, '2026-09-01', 5000.00, 150.10, 1234.56, 0.01, 0, 'partial');
        $this->repayment($loan, '2026-08-01', 5170.10, 5000.00, 150.10, 20.00);
        $this->repayment($loan, '2026-09-02', 1234.57, 1234.56, 0.01, 0);

        $data = $this->getJson("/api/reports/statement-of-account/{$loan->id}")->assertOk()->json('data');

        $this->assertEquals([5150.10, 1234.57], array_column($data['amortization_schedule'], 'amount_paid'));
        $this->assertEquals([
            'principal_due' => 10000.00,
            'interest_due' => 300.20,
            'penalty_amount' => 20.00,
            'total_due' => 10300.20,
            'amount_paid' => 6384.67,
        ], $data['schedule_totals']);

        $this->assertEquals([5170.10, 1234.57], array_column($data['transactions'], 'credit'));
        $this->assertSame([null, null], array_column($data['transactions'], 'debit'));
        $this->assertEquals(['debit' => 0, 'credit' => 6404.67], $data['transaction_totals']);
        $this->assertEquals($data['summary']['total_paid'], $data['transaction_totals']['credit']);
    }

    public function test_the_subsidiary_ledger_totals_principal_and_payments(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->north->id]);
        $first = $this->loan($this->north, 10000.10, $borrower);
        $second = $this->loan($this->north, 2500.25, $borrower);
        $this->repayment($first, '2026-09-01', 1000.15, 1000.15, 0, 0);
        $this->repayment($second, '2026-09-02', 0.10, 0.10, 0, 0);

        $data = $this->getJson("/api/reports/subsidiary-ledger/{$borrower->id}")->assertOk()->json('data');

        $this->assertEquals(12500.35, $data['totals']['total_principal']);
        $this->assertEquals($data['totals']['total_portfolio'], $data['totals']['total_principal']);
        $this->assertEquals(1000.25, $data['totals']['total_paid']);
        $this->assertEquals(array_sum(array_column($data['loans'], 'total_paid')), $data['totals']['total_paid']);
    }

    public function test_the_portfolio_summary_totals_its_composition_and_its_branches(): void
    {
        $north = $this->loan($this->north, 10000.10);
        $south = $this->loan($this->south, 2500.25);
        $this->schedule($north, now()->subDays(5)->toDateString(), 1000.10, 100.01, 0, 0, 5.05, 'overdue');
        $this->schedule($north, now()->addDays(25)->toDateString(), 9000.00, 90.00);
        $this->schedule($south, now()->addDays(25)->toDateString(), 2500.25, 25.03);

        $data = $this->getJson('/api/reports/loan-balance-summary')->assertOk()->json('data');

        $this->assertEquals(round($data['outstanding']['principal'] + $data['outstanding']['interest'] + $data['outstanding']['penalty'], 2), $data['outstanding']['total']);
        $this->assertEquals(round($data['overdue']['principal'] + $data['overdue']['interest'] + $data['overdue']['penalty'], 2), $data['overdue']['total']);
        $this->assertEquals(1105.16, $data['overdue']['total']);

        $this->assertEquals([
            'loan_count' => $data['portfolio']['loan_count'],
            'total_released' => $data['portfolio']['total_released'],
            'outstanding_balance' => $data['outstanding_balance'],
        ], $data['by_branch_totals']);
        $this->assertEquals(array_sum(array_column($data['by_branch'], 'loan_count')), $data['by_branch_totals']['loan_count']);
    }

    public function test_cash_flow_totals_its_branch_rows_to_the_loan_cash_lines(): void
    {
        $north = $this->loan($this->north, 10000.10, releasedAt: '2026-09-05 10:00:00', netProceeds: 9800.05);
        $south = $this->loan($this->south, 2500.25, releasedAt: '2026-09-06 10:00:00', netProceeds: 2450.20);
        $this->repayment($north, '2026-09-10', 1000.15, 900.10, 100.05, 0);
        $this->repayment($south, '2026-09-11', 250.30, 250.30, 0, 0);

        $data = $this->getJson('/api/reports/cash-flow?date_from=2026-09-01&date_to=2026-09-30')->assertOk()->json('data');

        $this->assertEquals([
            'inflow_total' => $data['inflows']['repayments']['total'],
            'outflow_total' => $data['outflows']['releases']['total'],
            'net_movement' => round($data['inflows']['repayments']['total'] - $data['outflows']['releases']['total'], 2),
        ], $data['by_branch_totals']);
        $this->assertEquals(1250.45, $data['by_branch_totals']['inflow_total']);
        $this->assertEquals(12250.25, $data['by_branch_totals']['outflow_total']);
    }

    public function test_performance_totals_each_table_and_both_agree(): void
    {
        $officer = User::factory()->create(['branch_id' => $this->north->id, 'first_name' => 'Ana', 'last_name' => 'Officer']);
        $north = $this->loan($this->north, 10000.10, releasedAt: '2026-09-05 10:00:00', officerId: $officer->id);
        $south = $this->loan($this->south, 2500.25, releasedAt: '2026-09-06 10:00:00');
        $this->schedule($north, now()->subDays(5)->toDateString(), 1000.10, 100.01, 0, 0, 0, 'overdue');
        $this->schedule($south, now()->addDays(25)->toDateString(), 2500.25, 25.03);
        $this->repayment($north, '2026-09-10', 1000.15, 900.10, 100.05, 0);

        $data = $this->getJson('/api/reports/performance?date_from=2026-09-01&date_to=2026-09-30')->assertOk()->json('data');

        foreach (['by_officer', 'by_branch'] as $table) {
            $rows = $data[$table];
            $this->assertEquals([
                'released_count' => array_sum(array_column($rows, 'released_count')),
                'released_amount' => round(array_sum(array_column($rows, 'released_amount')), 2),
                'collected' => round(array_sum(array_column($rows, 'collected')), 2),
                'outstanding' => round(array_sum(array_column($rows, 'outstanding')), 2),
                'overdue_amount' => round(array_sum(array_column($rows, 'overdue_amount')), 2),
            ], $data["{$table}_totals"], $table);
        }

        $this->assertEquals($data['by_officer_totals'], $data['by_branch_totals']);
        $this->assertEquals($data['by_officer_totals'], $data['totals']);
        $this->assertEquals(12500.35, $data['totals']['released_amount']);
        $this->assertEquals(1000.15, $data['totals']['collected']);
    }

    public function test_share_capital_totals_its_months_and_members_to_its_headline(): void
    {
        $ana = Borrower::factory()->create(['branch_id' => $this->north->id]);
        $ben = Borrower::factory()->create(['branch_id' => $this->south->id]);
        ShareCapitalLedger::factory()->create(['borrower_id' => $ana->id, 'credit' => 500.10, 'debit' => 0, 'date' => '2026-07-15']);
        ShareCapitalLedger::factory()->create(['borrower_id' => $ana->id, 'credit' => 250.25, 'debit' => 0, 'date' => '2026-08-15']);
        ShareCapitalLedger::factory()->create(['borrower_id' => $ben->id, 'credit' => 1000.00, 'debit' => 0, 'date' => '2026-08-20']);
        ShareCapitalLedger::factory()->create(['borrower_id' => $ben->id, 'credit' => 0, 'debit' => 100.05, 'date' => '2026-09-01']);

        $data = $this->getJson('/api/reports/share-capital?date_from=2026-08-01&date_to=2026-09-30')->assertOk()->json('data');

        $this->assertEquals([
            'credits' => $data['credits'],
            'debits' => $data['debits'],
            'net_movement' => $data['net_movement'],
        ], $data['by_month_totals']);
        $this->assertEquals([
            'opening_balance' => $data['opening_balance'],
            'credits' => $data['credits'],
            'debits' => $data['debits'],
            'closing_balance' => $data['closing_balance'],
        ], $data['by_member_totals']);
        $this->assertEquals(1250.25, $data['credits']);
        $this->assertEquals(1650.30, $data['closing_balance']);
    }

    public function test_share_capital_member_totals_are_withheld_with_the_members(): void
    {
        $collector = User::factory()->create(['branch_id' => $this->north->id]);
        $collector->givePermissionTo('reports:view');
        $this->actingAs($collector);

        $data = $this->getJson('/api/reports/share-capital?date_from=2026-08-01&date_to=2026-09-30')->assertOk()->json('data');

        $this->assertNull($data['by_member']);
        $this->assertNull($data['by_member_totals']);
        $this->assertArrayHasKey('by_month_totals', $data);
    }

    public function test_the_reports_that_already_total_on_the_server_always_send_every_figure(): void
    {
        $loan = $this->loan($this->north, 10000.10, releasedAt: '2026-09-05 10:00:00');
        $this->schedule($loan, '2026-09-20', 1000.10, 100.01, 0, 0, 0, 'overdue');

        $efficiency = $this->getJson('/api/reports/collection-efficiency?date_from=2026-09-01&date_to=2026-09-30')->assertOk()->json('data');
        foreach (['total_due', 'total_collected', 'uncollected', 'collection_rate'] as $key) {
            $this->assertArrayHasKey($key, $efficiency);

            foreach ([...$efficiency['by_branch'], ...$efficiency['by_period']] as $row) {
                $this->assertArrayHasKey($key, $row);
            }
        }

        $byProduct = $this->getJson('/api/reports/portfolio-by-product')->assertOk()->json('data');
        foreach (['product_count', 'loan_count', 'total_released', 'outstanding', 'overdue_amount'] as $key) {
            $this->assertArrayHasKey($key, $byProduct['totals']);
        }

        $provisioning = $this->getJson('/api/reports/provisioning')->assertOk()->json('data');
        foreach (['amount', 'required_allowance', 'effective_rate', 'count'] as $key) {
            $this->assertArrayHasKey($key, $provisioning['totals']);
        }
        foreach ($provisioning['buckets'] as $bucket) {
            $this->assertArrayHasKey('rate_percent', $bucket);
            $this->assertArrayHasKey('required_allowance', $bucket);
        }
    }

    private function loan(
        Branch $branch,
        float $principal,
        ?Borrower $borrower = null,
        string $releasedAt = '2026-06-01 10:00:00',
        ?float $netProceeds = null,
        ?int $officerId = null,
    ): Loan {
        return Loan::factory()->create([
            'borrower_id' => ($borrower ?? Borrower::factory()->create(['branch_id' => $branch->id]))->id,
            'loan_product_id' => $this->product->id,
            'branch_id' => $branch->id,
            'created_by' => $this->admin->id,
            'account_officer_id' => $officerId,
            'principal_amount' => $principal,
            'net_proceeds' => $netProceeds ?? $principal,
            'total_deductions' => round($principal - ($netProceeds ?? $principal), 2),
            'deductions' => null,
            'insurance_remaining_balance' => 0,
            'status' => 'ongoing',
            'released_at' => $releasedAt,
        ]);
    }

    private function schedule(
        Loan $loan,
        string $dueDate,
        float $principalDue,
        float $interestDue = 0,
        float $principalPaid = 0,
        float $interestPaid = 0,
        float $penalty = 0,
        string $status = 'pending',
    ): AmortizationSchedule {
        return AmortizationSchedule::factory()->create([
            'loan_id' => $loan->id,
            'period_number' => ++$this->period,
            'due_date' => $dueDate,
            'principal_due' => $principalDue,
            'interest_due' => $interestDue,
            'total_due' => round($principalDue + $interestDue, 2),
            'principal_paid' => $principalPaid,
            'interest_paid' => $interestPaid,
            'penalty_amount' => $penalty,
            'penalty_paid' => $status === 'paid' ? $penalty : 0,
            'status' => $status,
        ]);
    }

    private function repayment(Loan $loan, string $date, float $paid, float $principal, float $interest, float $penalty): Repayment
    {
        return Repayment::factory()->create([
            'loan_id' => $loan->id,
            'payment_date' => $date,
            'amount_paid' => $paid,
            'principal_applied' => $principal,
            'interest_applied' => $interest,
            'penalty_applied' => $penalty,
            'status' => 'posted',
            'received_by' => $this->admin->id,
        ]);
    }
}
