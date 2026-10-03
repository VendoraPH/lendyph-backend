<?php

namespace Tests\Feature;

use App\Models\ShareCapitalLedger;
use App\Services\RepaymentService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Every figure the printable documents used to work out in the browser, sent
 * by the endpoint that feeds each one, in whole centavos.
 */
class PrintableFiguresTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── disclosure statement: GET /loans/{id}/disclosure ─────────────────

    public function test_the_disclosure_states_the_finance_charges_and_the_amortization_total(): void
    {
        $loan = $this->createReleasedLoan(['product' => ['processing_fee' => 2, 'service_fee' => 0]]);

        $totals = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data.totals');

        // ₱1,200 withheld + ₱10,800 interest (₱1,800 × 6).
        $this->assertEquals(10800, $totals['total_interest']);
        $this->assertEquals(12000, $totals['total_finance_charges']);
        $this->assertEquals(70800, $totals['total_amortization']);
        $this->assertEquals(0, $totals['unitemised_deductions']);
    }

    public function test_the_disclosure_states_deductions_its_items_do_not_explain(): void
    {
        $loan = $this->createReleasedLoan(['product' => ['processing_fee' => 2, 'service_fee' => 0]]);
        DB::table('loans')->where('id', $loan->id)->update(['total_deductions' => 1250.25, 'net_proceeds' => 58749.75]);

        $totals = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data.totals');

        $this->assertEquals(50.25, $totals['unitemised_deductions']);
        $this->assertEquals(12050.25, $totals['total_finance_charges']);
    }

    // ── release voucher and amortization schedule: GET /loans/{id} ───────

    public function test_the_loan_states_unitemised_deductions_and_the_schedule_column_totals(): void
    {
        $loan = $this->createReleasedLoan(['product' => ['processing_fee' => 2, 'service_fee' => 0]]);
        DB::table('loans')->where('id', $loan->id)->update(['total_deductions' => 1250.25, 'net_proceeds' => 58749.75]);

        $first = $loan->amortizationSchedules->first();
        app(RepaymentService::class)->processRepayment($loan->fresh(), (float) $first->total_due, now()->toDateString(), $this->admin);
        DB::table('amortization_schedules')->where('id', $loan->amortizationSchedules->last()->id)->update(['penalty_amount' => 12.34]);

        $data = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');

        $this->assertEquals(50.25, $data['unitemised_deductions']);
        $this->assertEquals([
            'principal' => 60000,
            'interest' => 10800,
            'penalty' => 12.34,
            'total_due' => 70800,
            'amount_paid' => 11800,
        ], $data['amortization_schedule_totals']);
    }

    public function test_a_loan_whose_items_explain_its_deductions_has_none_unitemised(): void
    {
        $loan = $this->createReleasedLoan(['product' => ['processing_fee' => 2, 'service_fee' => 0]]);

        $this->getJson("/api/loans/{$loan->id}")->assertOk()->assertJsonPath('data.unitemised_deductions', 0);
    }

    public function test_items_above_the_total_give_a_negative_unitemised_figure_on_the_loan_and_the_disclosure(): void
    {
        // ₱1,200 of items against a stated total of ₱1,150.75.
        $loan = $this->createReleasedLoan(['product' => ['processing_fee' => 2, 'service_fee' => 0]]);
        DB::table('loans')->where('id', $loan->id)->update(['total_deductions' => 1150.75, 'net_proceeds' => 58849.25]);

        $this->assertEquals(-49.25, $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data.unitemised_deductions'));

        $totals = $this->getJson("/api/loans/{$loan->id}/disclosure")->assertOk()->json('data.totals');
        $this->assertEquals(-49.25, $totals['unitemised_deductions']);
        $this->assertEquals(11950.75, $totals['total_finance_charges']);
    }

    // ── demand letter: GET /reports/statement-of-account/{loan} ──────────

    public function test_the_statement_of_account_states_each_periods_arrears_and_the_total_demanded(): void
    {
        $loan = $this->createReleasedLoan();
        $schedules = $loan->amortizationSchedules;
        // Period 1 is 40 days late with ₱900 of its interest paid and a ₱200
        // penalty; period 2 fell due yesterday; period 3 falls due today.
        DB::table('amortization_schedules')->where('id', $schedules[0]->id)->update([
            'due_date' => now()->subDays(40)->toDateString(), 'interest_paid' => 900, 'penalty_amount' => 200, 'status' => 'partial',
        ]);
        DB::table('amortization_schedules')->where('id', $schedules[1]->id)->update(['due_date' => now()->subDay()->toDateString()]);
        DB::table('amortization_schedules')->where('id', $schedules[2]->id)->update(['due_date' => now()->toDateString()]);

        $data = $this->getJson("/api/reports/statement-of-account/{$loan->id}")->assertOk()->json('data');
        $rows = collect($data['amortization_schedule'])->keyBy('period_number');

        $this->assertEquals(['principal' => 10000, 'interest' => 900, 'penalty' => 200], $rows[1]['remaining']);
        $this->assertEquals(11100, $rows[1]['amount_due']);
        $this->assertSame(40, $rows[1]['days_overdue']);
        $this->assertTrue($rows[1]['is_overdue']);

        $this->assertSame(1, $rows[2]['days_overdue']);
        $this->assertTrue($rows[2]['is_overdue']);
        $this->assertSame(0, $rows[3]['days_overdue']);
        $this->assertFalse($rows[3]['is_overdue']);
        $this->assertEquals(11800, $rows[3]['amount_due']);

        $this->assertEquals(22900, $data['total_demanded']);
        $this->assertEquals([
            'principal' => 20000,
            'interest' => 2700,
            'penalty' => 200,
            'amount_due' => 22900,
        ], $data['demand_totals']);
    }

    // ── member ledger card: GET /reports/subsidiary-ledger/{borrower} ────

    public function test_the_subsidiary_ledger_totals_every_column_of_its_loans_table(): void
    {
        $first = $this->createReleasedLoan();
        $second = $this->createReleasedLoan(['principal_amount' => 30000.55]);
        DB::table('loans')->where('id', $second->id)->update(['borrower_id' => $first->borrower_id]);
        app(RepaymentService::class)->processRepayment($first->fresh(), 500.10, now()->toDateString(), $this->admin);
        app(RepaymentService::class)->processRepayment($first->fresh(), 100, now()->toDateString(), $this->admin);

        $data = $this->getJson("/api/reports/subsidiary-ledger/{$first->borrower_id}")->assertOk()->json('data');
        $rows = $data['loans'];

        $this->assertEquals([
            'principal_amount' => 90000.55,
            'total_paid' => 600.10,
            'payments_count' => 2,
            'outstanding_balance' => round($rows[0]['outstanding_balance'] + $rows[1]['outstanding_balance'], 2),
        ], $data['loans_totals']);
    }

    // ── share capital certificate: GET /reports/share-capital-statement ──

    public function test_the_share_capital_statement_states_its_credit_and_debit_totals(): void
    {
        $loan = $this->createReleasedLoan();
        foreach ([[1000.10, 0], [0, 200.05], [500, 0]] as $i => [$credit, $debit]) {
            ShareCapitalLedger::factory()->create([
                'borrower_id' => $loan->borrower_id,
                'date' => now()->subDays(3 - $i)->toDateString(),
                'credit' => $credit,
                'debit' => $debit,
            ]);
        }

        $data = $this->getJson("/api/reports/share-capital-statement/{$loan->borrower_id}")->assertOk()->json('data');

        $this->assertEquals(1500.10, $data['total_credit']);
        $this->assertEquals(200.05, $data['total_debit']);
        $this->assertEquals(1300.05, $data['closing_balance']);
        $this->assertEquals([1000.10, 800.05, 1300.05], array_column($data['entries'], 'running_balance'));
    }
}
