<?php

namespace Tests\Feature\FxReports;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\Role;
use App\Models\User;
use App\Services\LoanAdjustmentService;
use App\Services\RepaymentService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Statement of Account's High findings (fix round fx, decision D3). Every
 * figure is the server's.
 *
 * The loan from createReleasedLoan(): ₱60,000.00 over 6 monthly periods of
 * ₱10,000.00 principal and ₱1,800.00 interest.
 */
class StatementOfAccountHighFindingsTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── 1. Balance adjustments ───────────────────────────────────────────

    public function test_a_lowering_adjustment_is_its_own_line_and_the_balance_closes_on_the_ledger_principal(): void
    {
        $loan = $this->createReleasedLoan();
        $this->pay($loan, 11800);
        $adjustment = $this->applyBalanceAdjustment($loan, -600);

        $data = $this->statement($loan);

        $this->assertSame(['release', 'payment', 'adjustment'], array_column($data['transactions'], 'type'));

        [, $payment, $line] = $data['transactions'];
        $this->assertEquals(50000, $payment['principal_balance']);
        $this->assertEquals(600, $line['credit']);
        $this->assertNull($line['debit']);
        $this->assertEquals(0, $line['amount_paid'], 'An adjustment receives no money.');
        $this->assertEquals(600, $line['principal_applied'], 'Positive lowers the balance, as on a payment.');
        $this->assertEquals(49400, $line['principal_balance']);
        $this->assertEquals(49400, $line['balance'], 'The alias of principal_balance.');
        $this->assertEquals(49400, $line['running_balance']);
        $this->assertSame("Balance adjustment {$adjustment->adjustment_number}", $line['particulars']);
        $this->assertNull($line['payment_method']);
        $this->assertNull($line['reference_number']);

        $this->assertEquals($this->ledgerClosingPrincipal($loan), $line['principal_balance']);
        $this->assertEquals(11800, $data['summary']['total_paid'], 'Total Paid stays the money received.');
    }

    public function test_a_raising_adjustment_is_a_debit_and_raises_the_balance(): void
    {
        $loan = $this->createReleasedLoan();
        $this->applyBalanceAdjustment($loan, 500);

        $line = $this->statement($loan)['transactions'][1];

        $this->assertSame('adjustment', $line['type']);
        $this->assertEquals(500, $line['debit']);
        $this->assertNull($line['credit']);
        $this->assertEquals(-500, $line['principal_applied']);
        $this->assertEquals(60500, $line['principal_balance']);
        $this->assertEquals($this->ledgerClosingPrincipal($loan), $line['principal_balance']);
    }

    public function test_the_debit_and_credit_totals_include_the_adjustment_lines(): void
    {
        $loan = $this->createReleasedLoan();
        $this->pay($loan, 11800);
        $this->applyBalanceAdjustment($loan, -600);

        $this->assertEquals(['debit' => 70800, 'credit' => 12400, 'overpayment_applied' => 0], $this->statement($loan)['transaction_totals']);
    }

    public function test_the_schedule_carries_a_note_only_once_an_adjustment_restated_it(): void
    {
        $loan = $this->createReleasedLoan();
        $this->assertNull($this->statement($loan)['schedule_note']);

        $adjustment = $this->applyBalanceAdjustment($loan, -600);

        $note = $this->statement($loan)['schedule_note'];
        $this->assertStringContainsString('restated', $note);
        $this->assertStringContainsString("balance adjustment {$adjustment->adjustment_number}", $note);
        $this->assertStringContainsString($adjustment->fresh()->applied_at->format('M j, Y'), $note);
    }

    public function test_a_voided_payment_moves_no_balance(): void
    {
        $loan = $this->createReleasedLoan();
        $voided = $this->pay($loan, 11800);
        app(RepaymentService::class)->voidRepayment($voided, 'entered twice', $this->admin);
        $this->pay($loan, 11800);

        $transactions = $this->statement($loan)['transactions'];

        $this->assertCount(3, $transactions);
        $this->assertTrue($transactions[1]['voided']);
        $this->assertEquals(60000, $transactions[1]['principal_balance']);
        $this->assertEquals(50000, $transactions[2]['principal_balance']);
        $this->assertEquals($this->ledgerClosingPrincipal($loan), $transactions[2]['principal_balance']);
    }

    public function test_a_restructured_source_closes_on_the_ledger_principal_with_a_line_for_what_moved(): void
    {
        $source = $this->createReleasedLoan();
        $this->pay($source, 11800);

        $newId = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => 59000,
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

        $last = collect($this->statement($source)['transactions'])->last();

        $this->assertSame('adjustment', $last['type']);
        $this->assertSame('Balance moved to a restructured loan', $last['particulars']);
        $this->assertEquals(0, $last['principal_balance']);
        $this->assertEquals($this->ledgerClosingPrincipal($source), $last['principal_balance']);
    }

    // ── 2. Each line adds up to what it received ─────────────────────────

    public function test_each_payment_line_splits_into_its_parts_and_steps_the_principal_balance(): void
    {
        $loan = $this->createReleasedLoan();
        DB::table('amortization_schedules')->where('loan_id', $loan->id)->where('period_number', 1)
            ->update(['due_date' => now()->subDays(20)->toDateString()]);
        $loan->update(['insurance_remaining_balance' => 400]);

        $this->pay($loan, 10000);

        $line = $this->paymentLines($this->statement($loan))[0];

        $this->assertSame('payment', $line['type']);
        $this->assertEquals(400, $line['insurance_applied']);
        $this->assertEquals(200, $line['penalty_applied'], '2% of the period\'s ₱10,000.00.');
        $this->assertEquals(1800, $line['interest_applied']);
        $this->assertEquals(7600, $line['principal_applied']);
        $this->assertEquals(0, $line['build_up_applied']);
        $this->assertEquals(10000, $this->sumOfParts($line), 'The line adds up to the amount received.');
        $this->assertEquals(52400, $line['principal_balance'], 'Principal only: no premium in it.');
        $this->assertEquals(52400, $line['balance']);
    }

    public function test_a_build_up_payment_shows_the_build_up_in_its_split(): void
    {
        $loan = $this->buildUpLoan();

        $this->pay($loan, 11900);

        $line = $this->paymentLines($this->statement($loan))[0];

        $this->assertEquals(100, $line['build_up_applied']);
        $this->assertEquals(11900, $this->sumOfParts($line));
        $this->assertEquals(11900, $line['credit']);
    }

    public function test_a_payoff_with_an_excess_adds_up_with_the_excess_on_its_own_figure(): void
    {
        $loan = $this->createReleasedLoan();

        $this->pay($loan, 70850);

        $data = $this->statement($loan);
        $line = $this->paymentLines($data)[0];

        $this->assertEquals(70850, $line['credit']);
        $this->assertEquals(50, $line['overpayment_applied']);
        $this->assertEquals(0, $line['build_up_applied']);
        $this->assertEquals(70850, $this->sumOfParts($line));
        $this->assertEquals(0, $line['principal_balance'], 'The excess is not principal.');
        $this->assertEquals(50, $data['transaction_totals']['overpayment_applied']);
    }

    public function test_an_excess_credited_to_build_up_is_not_counted_twice(): void
    {
        $loan = $this->buildUpLoan();

        $this->pay($loan, 11950);

        $data = $this->statement($loan);
        $line = $this->paymentLines($data)[0];

        $this->assertEquals(150, $line['build_up_applied']);
        $this->assertEquals(0, $line['overpayment_applied']);
        $this->assertEquals(11950, $this->sumOfParts($line));
        $this->assertEquals(0, $data['transaction_totals']['overpayment_applied']);
    }

    public function test_an_excess_a_build_up_loan_could_not_credit_stays_an_overpayment(): void
    {
        $loan = $this->buildUpLoan();
        DB::table('borrowers')->where('id', $loan->borrower_id)->update(['status' => 'pending']);

        $this->pay($loan, 11950);

        $line = $this->paymentLines($this->statement($loan))[0];

        $this->assertEquals(0, $line['build_up_applied']);
        $this->assertEquals(150, $line['overpayment_applied']);
        $this->assertEquals(11950, $this->sumOfParts($line));
    }

    public function test_an_adjustment_line_has_no_overpayment(): void
    {
        $loan = $this->createReleasedLoan();
        $this->applyBalanceAdjustment($loan, -600);

        $this->assertEquals(0, $this->statement($loan)['transactions'][1]['overpayment_applied']);
    }

    public function test_an_extension_s_collection_reads_as_an_extension_payment(): void
    {
        $this->travelTo('2026-04-27 09:00:00');
        $loan = $this->createReleasedLoan([
            'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly', 'interest_rate' => 3.0],
            'start_date' => '2026-04-27',
        ]);
        $this->travelTo('2026-05-20 10:00:00');

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $line = $this->paymentLines($this->statement($loan))[0];

        $this->assertSame('payment', $line['type']);
        $this->assertSame("Extension payment {$line['receipt_number']}", $line['particulars']);
    }

    // ── 3 and 7. Total Due, build-up and Total Payment ───────────────────

    public function test_a_schedule_row_s_total_due_and_the_column_total_include_penalty(): void
    {
        $loan = $this->createReleasedLoan();
        DB::table('amortization_schedules')->where('loan_id', $loan->id)->where('period_number', 2)
            ->update(['penalty_amount' => 121.85]);

        $data = $this->statement($loan);
        $rows = collect($data['amortization_schedule'])->keyBy('period_number');

        $this->assertEquals(11800, $rows[1]['total_due']);
        $this->assertEquals(11921.85, $rows[2]['total_due']);
        $this->assertEquals(70921.85, $data['schedule_totals']['total_due']);
        $this->assertEquals(60000, $data['schedule_totals']['principal_due']);
        $this->assertEquals(121.85, $data['schedule_totals']['penalty_amount']);
    }

    public function test_a_row_s_total_due_less_its_total_paid_is_its_balance(): void
    {
        $loan = $this->createReleasedLoan();
        DB::table('amortization_schedules')->where('loan_id', $loan->id)->where('period_number', 1)
            ->update(['due_date' => now()->subDays(20)->toDateString()]);

        // Period 1 in full, its ₱200.00 penalty included, then ₱5,000.00 on period 2.
        $this->pay($loan, 17000);

        // Part of a penalty and of the principal paid.
        DB::table('amortization_schedules')->where('loan_id', $loan->id)->where('period_number', 4)
            ->update(['penalty_amount' => 100, 'penalty_paid' => 40, 'principal_paid' => 1000]);

        $data = $this->statement($loan);
        $rows = collect($data['amortization_schedule'])->keyBy('period_number');

        // Fully paid, with penalty.
        $this->assertEquals(12000, $rows[1]['total_due']);
        $this->assertEquals(12000, $rows[1]['total_paid']);
        $this->assertEquals(11800, $rows[1]['amount_paid'], 'Stays principal and interest.');
        $this->assertEquals(0, $rows[1]['amount_remaining']);

        // Part paid.
        $this->assertEquals(11800, $rows[2]['total_due']);
        $this->assertEquals(5000, $rows[2]['total_paid']);
        $this->assertEquals(6800, $rows[2]['amount_remaining']);

        // Nothing paid.
        $this->assertEquals(0, $rows[3]['total_paid']);
        $this->assertEquals(11800, $rows[3]['amount_remaining']);

        // Part of its penalty paid.
        $this->assertEquals(11900, $rows[4]['total_due']);
        $this->assertEquals(1040, $rows[4]['total_paid']);
        $this->assertEquals(1000, $rows[4]['amount_paid'], 'Stays principal and interest.');
        $this->assertEquals(10860, $rows[4]['amount_remaining']);

        foreach ($rows as $period => $row) {
            $this->assertEquals($row['amount_remaining'], round($row['total_due'] - $row['total_paid'], 2), "Period {$period}.");
        }

        $this->assertEquals(71100, $data['schedule_totals']['total_due']);
        $this->assertEquals(18040, $data['schedule_totals']['total_paid']);
        $this->assertEquals(17800, $data['schedule_totals']['amount_paid'], 'Stays principal and interest.');
        $this->assertEquals(
            round(collect($data['amortization_schedule'])->sum('amount_remaining'), 2),
            round($data['schedule_totals']['total_due'] - $data['schedule_totals']['total_paid'], 2),
        );
    }

    public function test_a_build_up_loan_s_schedule_carries_the_build_up_and_total_payment(): void
    {
        $loan = $this->buildUpLoan();
        DB::table('amortization_schedules')->where('loan_id', $loan->id)->where('period_number', 2)
            ->update(['penalty_amount' => 50]);

        $data = $this->statement($loan);
        $rows = collect($data['amortization_schedule'])->keyBy('period_number');

        $this->assertTrue($data['loan']['has_build_up']);
        $this->assertEquals(100, $rows[1]['build_up_due']);
        $this->assertEquals(11900, $rows[1]['total_payment']);
        $this->assertEquals(11850, $rows[2]['total_due']);
        $this->assertEquals(11950, $rows[2]['total_payment']);
        $this->assertEquals(600, $data['schedule_totals']['build_up_due']);
        $this->assertEquals(71450, $data['schedule_totals']['total_payment']);

        // Outstanding Balance stays without the build-up.
        $this->assertEquals(70850, $data['summary']['outstanding_balance']);
    }

    public function test_a_loan_without_a_build_up_says_so_with_real_zero_figures(): void
    {
        $data = $this->statement($this->createReleasedLoan());

        $this->assertFalse($data['loan']['has_build_up']);
        $this->assertEquals(0, $data['amortization_schedule'][0]['build_up_due']);
        $this->assertEquals(11800, $data['amortization_schedule'][0]['total_payment']);
        $this->assertEquals(0, $data['schedule_totals']['build_up_due']);
        $this->assertEquals(70800, $data['schedule_totals']['total_payment']);
    }

    // ── 4. Method and reference ──────────────────────────────────────────

    public function test_the_stored_method_and_reference_number_are_on_the_line(): void
    {
        $loan = $this->createReleasedLoan();
        app(RepaymentService::class)->processRepayment($loan->fresh(), 1000, now()->toDateString(), $this->admin, null, 'gcash', 'GC12345678');
        $this->pay($loan, 1000);

        [$gcash, $cash] = $this->paymentLines($this->statement($loan));

        $this->assertSame('GCash', $gcash['payment_method']);
        $this->assertSame('GC12345678', $gcash['reference_number']);
        $this->assertSame('Cash', $cash['payment_method']);
        $this->assertNull($cash['reference_number']);
    }

    // ── 5. As of ─────────────────────────────────────────────────────────

    public function test_the_statement_states_its_as_of_date_in_the_app_timezone(): void
    {
        $this->travelTo('2026-11-05 22:30:00 UTC');

        $this->assertSame('2026-11-06', $this->statement($this->createReleasedLoan())['as_of']);
    }

    // ── 6. Status ────────────────────────────────────────────────────────

    public function test_a_loan_with_a_past_due_amount_reads_past_due(): void
    {
        $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(3)->toDateString()]);

        $data = $this->statement($loan);

        $this->assertGreaterThan(0, $data['summary']['overdue_amount']);
        $this->assertSame('Past Due', $data['loan']['status_label']);
    }

    public function test_a_loan_inside_grace_reads_current(): void
    {
        $loan = $this->createReleasedLoan();
        AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', 1)
            ->update(['due_date' => now()->subDays(2)->toDateString()]);

        $data = $this->statement($loan);

        $this->assertEquals(0, $data['summary']['overdue_amount']);
        $this->assertSame('Current', $data['loan']['status_label']);
    }

    public function test_every_other_status_reads_the_app_s_label(): void
    {
        $labels = [
            'draft' => 'Draft',
            'for_review' => 'For Approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'released' => 'Current',
            'ongoing' => 'Current',
            'completed' => 'Completed',
            'defaulted' => 'Defaulted',
            'restructured' => 'Restructured',
            'void' => 'Voided',
        ];

        foreach ($labels as $status => $label) {
            $loan = $this->createReleasedLoan();
            DB::table('loans')->where('id', $loan->id)->update(['status' => $status]);

            $this->assertSame($label, $this->statement($loan)['loan']['status_label'], $status);
        }
    }

    public function test_a_loan_nobody_can_collect_on_never_reads_past_due(): void
    {
        $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(3)->toDateString()]);
        DB::table('loans')->where('id', $loan->id)->update(['status' => 'void']);

        $this->assertSame('Voided', $this->statement($loan)['loan']['status_label']);
    }

    public function test_a_defaulted_loan_with_a_past_due_amount_keeps_reading_defaulted(): void
    {
        $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(3)->toDateString()]);
        DB::table('loans')->where('id', $loan->id)->update(['status' => 'defaulted']);

        $data = $this->statement($loan);

        $this->assertGreaterThan(0, $data['summary']['overdue_amount']);
        $this->assertSame('Defaulted', $data['loan']['status_label']);
    }

    public function test_an_ongoing_loan_with_a_past_due_amount_reads_past_due(): void
    {
        $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(3)->toDateString()]);
        DB::table('loans')->where('id', $loan->id)->update(['status' => 'ongoing']);

        $this->assertSame('Past Due', $this->statement($loan)['loan']['status_label']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function statement(Loan $loan): array
    {
        return $this->getJson("/api/reports/statement-of-account/{$loan->id}")->assertOk()->json('data');
    }

    /**
     * The statement's payment lines, without the release and charges around them.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function paymentLines(array $data): array
    {
        return array_values(array_filter($data['transactions'], fn (array $line): bool => $line['type'] === 'payment'));
    }

    private function ledgerClosingPrincipal(Loan $loan): float
    {
        return (float) $this->getJson("/api/loans/{$loan->id}/ledger")->assertOk()->json('data.closing.principal');
    }

    private function pay(Loan $loan, float $amount): Repayment
    {
        return app(RepaymentService::class)->processRepayment($loan->fresh(), $amount, now()->toDateString(), $this->admin);
    }

    private function applyBalanceAdjustment(Loan $loan, float $amount)
    {
        $service = app(LoanAdjustmentService::class);
        $adjustment = $service->createAdjustment($loan->fresh(), [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => $amount],
        ], $this->admin);
        $service->approveAdjustment($adjustment, $this->admin, 'ok');

        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/apply")->assertOk();

        return $adjustment->fresh();
    }

    private function buildUpLoan(): Loan
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['scb_amount' => 100]);

        return $loan->fresh('amortizationSchedules');
    }

    /** @param array<string, mixed> $line */
    private function sumOfParts(array $line): float
    {
        return round(
            $line['principal_applied'] + $line['interest_applied'] + $line['penalty_applied']
            + $line['insurance_applied'] + $line['build_up_applied'] + $line['overpayment_applied'],
            2,
        );
    }
}
