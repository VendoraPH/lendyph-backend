<?php

namespace Tests\Feature\InsuranceOwed;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use App\Services\LoanService;
use App\Services\RepaymentService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Reports count the premium a payment collected as its own figure: cash in on
 * Cash Flow (not interest, overall and per branch), never income, outside
 * Daily Collection's and Collection Efficiency's money taken in, totalled on
 * the repayments report, and run through the Statement of Account's balance.
 */
class InsuranceOwedReportsTest extends TestCase
{
    use OwesInsurancePremium, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function today(): string
    {
        return http_build_query(['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]);
    }

    public function test_cash_flow_shows_the_premium_collected_as_its_own_inflow(): void
    {
        $loan = $this->loanOwingPremium();
        $this->pay($loan, 1000);

        $repayments = $this->getJson('/api/reports/cash-flow?'.$this->today())
            ->assertOk()
            ->json('data.inflows.repayments');

        $this->assertEqualsWithDelta(self::OWED, (float) ($repayments['insurance'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(600.00, (float) $repayments['interest'], 0.001);
        // The parts still add up to every peso received.
        $this->assertEqualsWithDelta(1000.00, (float) $repayments['total'], 0.001);
    }

    public function test_cash_flow_by_branch_shows_the_premium_inside_the_branch_inflow(): void
    {
        $loan = $this->loanOwingPremium();
        $this->pay($loan, 1000);

        $row = collect($this->getJson('/api/reports/cash-flow?'.$this->today())->assertOk()->json('data.by_branch'))
            ->firstWhere('branch_id', $loan->branch_id);

        $this->assertNotNull($row, 'setup: the branch has a row');
        $this->assertEqualsWithDelta(self::OWED, (float) ($row['inflow_insurance'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(600.00, (float) $row['inflow_interest'], 0.001);
        $this->assertEqualsWithDelta(1000.00, (float) $row['inflow_total'], 0.001);
    }

    public function test_daily_collection_and_collection_efficiency_leave_the_premium_out_of_the_money_taken_in(): void
    {
        $loan = $this->loanOwingPremium();
        $this->pay($loan, 1000);

        foreach (['daily-collection', 'collection-efficiency'] as $report) {
            $data = $this->getJson("/api/reports/{$report}?".$this->today())->assertOk()->json('data');

            $this->assertEqualsWithDelta(600.00, (float) $data['total_received'], 0.001, $report);
            $this->assertEqualsWithDelta(0.00, (float) $data['penalty_collected'], 0.001, $report);
        }

        // Reconciles with Cash Flow less its insurance line.
        $repayments = $this->getJson('/api/reports/cash-flow?'.$this->today())->assertOk()->json('data.inflows.repayments');
        $daily = $this->getJson('/api/reports/daily-collection?'.$this->today())->assertOk()->json('data');
        $this->assertSame(
            (int) round(((float) $repayments['total'] - (float) ($repayments['insurance'] ?? 0)) * 100),
            (int) round((float) $daily['total_received'] * 100),
        );
    }

    public function test_the_repayments_report_totals_the_premium_collected(): void
    {
        $this->pay($this->loanOwingPremium(), 1000);
        $this->pay($this->loanOwingPremium(), 150);

        $this->getJson('/api/reports/repayments?'.$this->today())
            ->assertOk()
            ->assertJsonPath('totals.total_insurance_applied', fn ($v) => abs((float) $v - 550.00) < 0.001);
    }

    public function test_the_repayments_export_breaks_out_the_premium_collected(): void
    {
        $loan = $this->loanOwingPremium();
        $repayment = $this->pay($loan, 1000);

        $content = $this->get('/api/reports/repayments/export?'.$this->today())->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', array_values(array_filter(preg_split('/\r?\n/', ltrim($content, "\u{FEFF}")))));

        $header = $rows[0];
        $column = array_search('Insurance Premium', $header, true);
        $this->assertNotFalse($column, 'the export has no Insurance Premium column');

        $row = collect($rows)->first(fn (array $r): bool => ($r[0] ?? null) === $repayment->receipt_number);
        $this->assertNotNull($row, 'setup: the payment is in the export');
        $this->assertEqualsWithDelta(self::OWED, (float) $row[$column], 0.001);
        $this->assertEqualsWithDelta(1000.00, (float) $row[array_search('Amount', $header, true)], 0.001);
    }

    public function test_the_statement_of_account_runs_the_premium_through_its_balance(): void
    {
        $loan = $this->loanOwingPremium();
        $this->pay($loan, 150);
        $this->pay($loan, 1000);

        $data = $this->getJson("/api/reports/statement-of-account/{$loan->id}")->assertOk()->json('data');

        // Principal plus the premium the release left owed.
        $this->assertEqualsWithDelta(60400.00, (float) $data['summary']['opening_balance'], 0.001);

        [$first, $second] = $data['transactions'];
        $this->assertEqualsWithDelta(150.00, (float) ($first['insurance_applied'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(60250.00, (float) $first['running_balance'], 0.001);
        $this->assertEqualsWithDelta(250.00, (float) ($second['insurance_applied'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(60000.00, (float) $second['running_balance'], 0.001);

        $this->assertEqualsWithDelta((float) $loan->fresh()->outstanding_balance, (float) $second['running_balance'], 0.001);
        $this->assertEqualsWithDelta((float) $data['summary']['principal_balance'], (float) $second['running_balance'], 0.001);
    }

    /** @return array<string, mixed> */
    private function statement(Loan $loan): array
    {
        return $this->getJson("/api/reports/statement-of-account/{$loan->id}")->assertOk()->json('data');
    }

    public function test_voiding_a_payment_takes_its_premium_out_of_every_report_and_keeps_the_opening(): void
    {
        $loan = $this->loanOwingPremium();
        $this->pay($loan, 150);
        $voided = $this->pay($loan, 1000);

        $openingBefore = $this->statement($loan)['summary']['opening_balance'];

        app(RepaymentService::class)->voidRepayment($voided->fresh(), 'Keyed twice', $this->admin);

        $this->assertEqualsWithDelta(150.00, (float) $this->getJson('/api/reports/cash-flow?'.$this->today())->json('data.inflows.repayments.insurance'), 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $this->getJson('/api/reports/daily-collection?'.$this->today())->json('data.total_received'), 0.001);
        $this->assertEqualsWithDelta(150.00, (float) $this->getJson('/api/reports/repayments?'.$this->today())->json('totals.total_insurance_applied'), 0.001);

        $statement = $this->statement($loan);
        $this->assertEquals($openingBefore, $statement['summary']['opening_balance']);
        $this->assertEqualsWithDelta(60400.00, (float) $statement['summary']['opening_balance'], 0.001);
        $this->assertCount(1, $statement['transactions']);
        $this->assertEqualsWithDelta(150.00, (float) $statement['transactions'][0]['insurance_applied'], 0.001);
        $this->assertEqualsWithDelta(60250.00, (float) $statement['transactions'][0]['running_balance'], 0.001);
        $this->assertEqualsWithDelta((float) $loan->fresh()->outstanding_balance, (float) $statement['transactions'][0]['running_balance'], 0.001);
    }

    public function test_the_statements_of_a_restructure_open_with_the_premium_carried_on_both_loans(): void
    {
        $source = $this->loanOwingPremium();

        $newId = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => self::SCHEDULE_TOTAL,
            'start_date' => now()->toDateString(),
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loans/{$newId}/submit")->assertOk();
        $approver = User::factory()->create();
        $approver->assignRole(Role::where('name', 'admin')->first());
        $this->actingAs($approver);
        $this->patchJson("/api/loans/{$newId}/approve", ['approval_remarks' => 'ok'])->assertOk();
        $this->actingAs($this->admin);
        $this->patchJson("/api/loans/{$newId}/release")->assertOk();

        $this->assertSame('restructured', $source->fresh()->status);

        // The source still opens at principal + the ₱400 it carried out.
        $this->assertEqualsWithDelta(60400.00, (float) $this->statement($source)['summary']['opening_balance'], 0.001);

        // The new loan opens at its principal + the ₱400 carried in.
        $new = Loan::findOrFail($newId);
        $this->assertEqualsWithDelta(self::SCHEDULE_TOTAL + self::OWED, (float) $this->statement($new)['summary']['opening_balance'], 0.001);

        // Collecting it on the new loan moves neither opening.
        $this->pay($new, self::OWED);
        $this->assertEqualsWithDelta(60400.00, (float) $this->statement($source)['summary']['opening_balance'], 0.001);
        $this->assertEqualsWithDelta(self::SCHEDULE_TOTAL + self::OWED, (float) $this->statement($new)['summary']['opening_balance'], 0.001);
    }

    public function test_the_statement_of_a_loan_whose_premium_was_paid_in_full_at_release_opens_at_principal(): void
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly',
            'penalty_rate' => 2.0, 'grace_period_days' => 3,
        ]);
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $loans = app(LoanService::class);
        $loan = $loans->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ], $this->admin);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');
        $loans->release($loan, $this->admin, ['insurance_premium_percentage' => 1.0, 'insurance_payment_type' => 'full']);

        $this->assertEqualsWithDelta(0.00, $this->owed($loan), 0.001);
        $this->pay($loan, 1000);

        $statement = $this->statement($loan);
        $this->assertEqualsWithDelta(60000.00, (float) $statement['summary']['opening_balance'], 0.001);
        $this->assertSame([0.0], array_map('floatval', array_column($statement['transactions'], 'insurance_applied')));
    }

    public function test_the_branch_filter_keeps_each_branch_s_premium_apart(): void
    {
        $other = Branch::factory()->create();
        $here = $this->loanOwingPremium();
        $there = $this->loanOwingPremium();
        DB::table('loans')->where('id', $there->id)->update(['branch_id' => $other->id]);

        $this->pay($here, 1000);
        $this->pay($there, 150);

        $daily = fn (int $branch) => (float) $this->getJson('/api/reports/daily-collection?'.$this->today().'&branch_id='.$branch)
            ->assertOk()->json('data.total_received');
        $premium = fn (int $branch) => (float) $this->getJson('/api/reports/repayments?'.$this->today().'&branch_id='.$branch)
            ->assertOk()->json('totals.total_insurance_applied');

        $this->assertEqualsWithDelta(600.00, $daily($here->branch_id), 0.001);
        $this->assertEqualsWithDelta(0.00, $daily($other->id), 0.001);
        $this->assertEqualsWithDelta(self::OWED, $premium($here->branch_id), 0.001);
        $this->assertEqualsWithDelta(150.00, $premium($other->id), 0.001);
    }

    public function test_total_collected_stays_principal_and_interest_when_a_payment_collected_premium(): void
    {
        $loan = $this->loanOwingPremium();
        AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', 1)->update(['due_date' => now()->toDateString()]);

        $this->pay($loan, self::PERIOD_DUE + self::OWED);

        $data = $this->getJson('/api/reports/daily-collection?'.$this->today())->assertOk()->json('data');

        $this->assertEqualsWithDelta(self::PERIOD_DUE, (float) $data['total_collected'], 0.001);
        $this->assertEqualsWithDelta(self::PERIOD_DUE, (float) $data['total_received'], 0.001);
        $this->assertEqualsWithDelta(self::PERIOD_DUE, (float) $data['total_due'], 0.001);
    }

    public function test_the_statement_s_outstanding_balance_includes_the_premium_owed(): void
    {
        $loan = $this->loanOwingPremium();
        $this->pay($loan, 150);

        $summary = $this->statement($loan)['summary'];

        // ₱70,800 on the periods plus the ₱250 premium still owed.
        $this->assertEqualsWithDelta(self::SCHEDULE_TOTAL + 250, (float) $summary['outstanding_balance'], 0.001);
        $this->assertEqualsWithDelta(250.00, (float) ($summary['insurance_owed'] ?? -1), 0.001);
        $this->assertEqualsWithDelta(
            (float) $this->getJson("/api/loans/{$loan->id}")->json('data.total_payable'),
            (float) $summary['outstanding_balance'],
            0.001,
        );
        // Principal-side figures unchanged.
        $this->assertEqualsWithDelta(60000.00, (float) $summary['outstanding_principal'], 0.001);
        $this->assertEqualsWithDelta((float) $loan->fresh()->outstanding_balance, (float) $summary['principal_balance'], 0.001);
    }

    public function test_the_income_report_never_counts_the_premium_as_interest(): void
    {
        $loan = $this->loanOwingPremium();
        $this->pay($loan, 1000);

        $this->getJson('/api/reports/income?'.$this->today())
            ->assertOk()
            ->assertJsonPath('data.interest_income', fn ($v) => abs((float) $v - 600.00) < 0.001);
    }
}
