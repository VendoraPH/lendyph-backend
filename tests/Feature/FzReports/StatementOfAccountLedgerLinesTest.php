<?php

namespace Tests\Feature\FzReports;

use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\User;
use App\Services\RepaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Statement of Account's server side (round fz, findings 8-14 and 17).
 *
 * The loan from createReleasedLoan(): ₱60,000.00 over 6 monthly periods of
 * ₱10,000.00 principal and ₱1,800.00 interest, 2% penalty, 3 days grace.
 */
class StatementOfAccountLedgerLinesTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── Account details ──────────────────────────────────────────────────

    public function test_the_loan_block_carries_the_account_details_in_words(): void
    {
        $officer = User::factory()->create(['first_name' => 'Pedro', 'last_name' => 'Reyes']);
        $loan = $this->createReleasedLoan(['product' => ['name' => 'Salary Loan']]);
        $loan->update(['account_officer_id' => $officer->id]);
        $loan->borrower->update(['address' => 'Butuan City, Agusan del Norte']);

        $block = $this->statement($loan)['loan'];

        $this->assertSame('Salary Loan', $block['product_name']);
        $this->assertSame($loan->fresh()->released_at->toDateString(), $block['released_at']);
        $this->assertSame('Butuan City, Agusan del Norte', $block['member_address']);
        $this->assertSame($loan->branch->name, $block['branch_name']);
        $this->assertSame($officer->fresh()->full_name, $block['account_officer_name']);
        $this->assertSame('Monthly', $block['payment_frequency']);
        $this->assertSame('Straight', $block['interest_type']);
        $this->assertSame('6 months', $block['term_label']);
        $this->assertSame('3.0% per month', $block['rate_label']);
        $this->assertSame(0, $block['extensions_count']);
    }

    public function test_the_labels_keep_the_units_and_never_round_the_rate(): void
    {
        $loan = $this->createReleasedLoan();
        DB::table('loans')->where('id', $loan->id)->update([
            'interest_rate' => 2.75,
            'interest_rate_frequency' => 'semi_monthly',
            'interest_method' => 'upon_maturity',
            'frequency' => 'semi_monthly',
            'term' => 1,
        ]);

        $block = $this->statement($loan)['loan'];

        $this->assertSame('2.75% per half month', $block['rate_label']);
        $this->assertSame('1 month', $block['term_label']);
        $this->assertSame('Semi-Monthly', $block['payment_frequency']);
        $this->assertSame('Upon Maturity', $block['interest_type']);

        DB::table('loans')->where('id', $loan->id)->update(['term' => 45, 'term_unit' => 'days', 'interest_rate' => 1.0625]);

        $block = $this->statement($loan)['loan'];

        $this->assertSame('45 days', $block['term_label']);
        $this->assertSame('1.0625% per half month', $block['rate_label']);
    }

    public function test_a_loan_with_no_officer_or_address_sends_null_not_an_error(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['account_officer_id' => null]);
        DB::table('borrowers')->where('id', $loan->borrower_id)->update(['address' => null]);

        $block = $this->statement($loan)['loan'];

        $this->assertNull($block['account_officer_name']);
        $this->assertNull($block['member_address']);
    }

    // ── The paid figures reconcile ───────────────────────────────────────

    public function test_the_paid_parts_add_up_to_total_paid(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['scb_amount' => 100, 'insurance_remaining_balance' => 400]);
        DB::table('amortization_schedules')->where('loan_id', $loan->id)->where('period_number', 1)
            ->update(['due_date' => now()->subDays(20)->toDateString()]);

        // Premium ₱400.00, penalty ₱200.00, then the period's ₱11,800.00.
        $this->pay($loan, 12400);
        // The next period's ₱11,800.00 and its ₱100.00 build-up.
        $this->pay($loan, 11900);

        $voided = $this->pay($loan, 500);
        app(RepaymentService::class)->voidRepayment($voided, 'entered twice', $this->admin);

        $summary = $this->statement($loan)['summary'];

        $this->assertEquals(24300, $summary['total_paid']);
        $this->assertEquals(20000, $summary['principal_paid']);
        $this->assertEquals(3600, $summary['interest_paid']);
        $this->assertEquals(200, $summary['penalty_paid']);
        $this->assertEquals(400, $summary['insurance_paid']);
        $this->assertEquals(100, $summary['build_up_paid']);
        $this->assertEquals(0, $summary['overpayment_paid']);
        $this->assertEquals(0, $summary['insurance_owed']);
        $this->assertEquals($summary['total_paid'], $this->paidParts($summary));
    }

    public function test_an_excess_with_nowhere_to_go_keeps_the_parts_adding_up(): void
    {
        $loan = $this->createReleasedLoan();
        $this->pay($loan, 70850);

        $summary = $this->statement($loan)['summary'];

        $this->assertEquals(50, $summary['overpayment_paid']);
        $this->assertEquals(70850, $this->paidParts($summary));
    }

    public function test_the_premium_owed_is_in_the_summary_and_the_schedule_total_states_the_balance(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['insurance_remaining_balance' => 1000]);
        $this->pay($loan, 1000 + 2000);

        $loan->update(['insurance_remaining_balance' => 250]);

        $data = $this->statement($loan);

        $this->assertEquals(250, $data['summary']['insurance_owed']);
        $this->assertEquals(round(collect($data['amortization_schedule'])->sum('amount_remaining'), 2), $data['schedule_totals']['balance']);
        $this->assertEquals($data['summary']['outstanding_balance'], round($data['schedule_totals']['balance'] + 250, 2));
    }

    // ── The lines are the Ledger's ───────────────────────────────────────

    public function test_the_release_is_the_first_line_a_debit_of_principal_and_interest(): void
    {
        $loan = $this->createReleasedLoan();

        $data = $this->statement($loan);
        $line = $data['transactions'][0];

        $this->assertCount(1, $data['transactions']);
        $this->assertSame('release', $line['kind']);
        $this->assertSame('Loan released', $line['description']);
        $this->assertSame('Loan released', $line['particulars']);
        $this->assertEquals(70800, $line['debit']);
        $this->assertNull($line['credit']);
        $this->assertFalse($line['voided']);
        $this->assertEquals(0, $line['amount_paid']);
        $this->assertEquals(60000, $line['principal_balance']);
        $this->assertSame($loan->fresh()->released_at->toDateString(), $line['date']);
        $this->assertEquals(70800, $data['transaction_totals']['debit']);
        $this->assertEquals(0, $data['transaction_totals']['credit']);
    }

    public function test_penalty_charges_and_waivers_are_lines_and_move_no_principal(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $loan = $this->createReleasedLoan(['start_date' => '2026-01-15']);
        $this->travelTo(Carbon::parse('2026-03-01 09:00'));
        $this->artisan('loans:apply-penalties')->assertSuccessful();

        $this->pay($loan, 80);
        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['waive_all' => true],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        $lines = collect($this->statement($loan)['transactions']);

        $this->assertSame(['release', 'penalty_charge', 'repayment', 'adjustment'], $lines->pluck('kind')->all());

        $charge = $lines[1];
        $this->assertSame('Late penalty, period 1', $charge['description']);
        $this->assertEquals(200, $charge['debit']);
        $this->assertNull($charge['credit']);
        $this->assertEquals(60000, $charge['principal_balance']);

        $waiver = $lines[3];
        $this->assertStringStartsWith('Penalty waiver', $waiver['description']);
        $this->assertEquals(120, $waiver['credit']);
        $this->assertNull($waiver['debit']);
        $this->assertEquals(60000, $waiver['principal_balance']);
    }

    public function test_every_line_adds_up_and_the_debits_less_the_credits_are_what_is_owed(): void
    {
        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $loan = $this->createReleasedLoan(['start_date' => '2026-01-15']);
        $loan->update(['insurance_remaining_balance' => 400, 'scb_amount' => 100]);
        $this->travelTo(Carbon::parse('2026-04-01 09:00'));
        $this->artisan('loans:apply-penalties')->assertSuccessful();

        $this->pay($loan, 13000);
        $voided = $this->pay($loan, 700);
        app(RepaymentService::class)->voidRepayment($voided, 'entered twice', $this->admin);
        $this->pay($loan, 2000);
        $this->applyBalanceAdjustment($loan, -600);

        $data = $this->statement($loan);
        $outside = 0.0;

        foreach ($data['transactions'] as $line) {
            if ($line['voided']) {
                continue;
            }

            $this->assertEquals(
                round((float) $line['credit'] - (float) $line['debit'], 2),
                $this->sumOfParts($line),
                $line['description'],
            );

            // Money a payment took that the balances never held.
            $outside += $line['insurance_applied'] + $line['build_up_applied'] + $line['overpayment_applied'];
        }

        $summary = $data['summary'];
        $owed = round($summary['outstanding_principal'] + $summary['outstanding_interest'] + $summary['outstanding_penalty'], 2);

        $this->assertEquals(
            $owed,
            round($data['transaction_totals']['debit'] - $data['transaction_totals']['credit'] + $outside, 2),
        );
        $this->assertEquals($this->ledgerClosingPrincipal($loan), collect($data['transactions'])->last()['principal_balance']);
    }

    public function test_an_extension_lists_its_interest_and_labels_its_payment(): void
    {
        $this->travelTo('2026-04-27 09:00:00');
        $loan = $this->oneMonthLoan();
        $this->travelTo('2026-05-20 10:00:00');

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $data = $this->statement($loan);
        $lines = collect($data['transactions']);

        $this->assertSame(['release', 'extension_payment', 'extension_charge'], $lines->pluck('kind')->all());
        $this->assertStringStartsWith('Extension payment ', $lines[1]['description']);
        $this->assertSame('Extension payment '.$lines[1]['receipt_number'], $lines[1]['particulars']);
        $this->assertGreaterThan(0, $lines[1]['credit']);
        $this->assertGreaterThan(0, $lines[2]['debit']);
        $this->assertSame(1, $data['loan']['extensions_count']);
        $this->assertEquals($this->ledgerClosingPrincipal($loan), $lines->last()['principal_balance']);
    }

    // ── Voided payments ──────────────────────────────────────────────────

    public function test_a_voided_payment_is_listed_marked_with_no_effect_on_any_balance(): void
    {
        $loan = $this->createReleasedLoan();
        $before = $this->pay($loan, 11800);
        $voided = $this->pay($loan, 1000);
        app(RepaymentService::class)->voidRepayment($voided, 'entered twice', $this->admin);
        $this->pay($loan, 11800);

        $data = $this->statement($loan);
        $lines = collect($data['transactions']);

        $this->assertSame(['release', 'repayment', 'repayment', 'repayment'], $lines->pluck('kind')->all(), 'One line for the payment, none for its void.');
        $this->assertSame([false, false, true, false], $lines->pluck('voided')->all());

        $line = $lines[2];
        $this->assertSame($voided->receipt_number, $line['receipt_number']);
        $this->assertSame("Payment {$voided->receipt_number} (voided)", $line['description']);
        $this->assertEquals(1000, $line['amount_paid'], 'The receipt\'s amount stays readable.');
        $this->assertNull($line['credit']);
        $this->assertNull($line['debit']);
        $this->assertEquals(0, $this->sumOfParts($line));
        $this->assertEquals($lines[1]['principal_balance'], $line['principal_balance']);
        $this->assertEquals($lines[1]['running_balance'], $line['running_balance']);

        $this->assertEquals(23600, $data['transaction_totals']['credit'], 'The voided amount is not in the total.');
        $this->assertEquals(23600, $data['summary']['total_paid']);
        $this->assertEquals($this->ledgerClosingPrincipal($loan), $lines->last()['principal_balance']);
        $this->assertSame($before->receipt_number, $lines[1]['receipt_number']);
    }

    // ── Rows an extension replaced ───────────────────────────────────────

    public function test_a_deferred_extension_s_new_period_says_which_it_replaced(): void
    {
        $this->travelTo('2026-04-27 09:00:00');
        $loan = $this->oneMonthLoan();
        $this->travelTo('2026-05-20 10:00:00');

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        $data = $this->statement($loan);
        $rows = collect($data['amortization_schedule']);
        $number = $loan->adjustments()->where('adjustment_type', 'extension')->value('adjustment_number');

        $this->assertSame([2], $rows->pluck('period_number')->all(), 'Period 1 had nothing collected, so it is gone.');
        $this->assertSame("Replaces period 1: extension {$number} on May 20, 2026.", $rows[0]['note']);
        $this->assertSame("Period 1 was replaced by period 2: extension {$number} on May 20, 2026.", $data['schedule_note']);
    }

    public function test_a_period_an_extension_closed_says_so_and_the_new_one_names_it(): void
    {
        $this->travelTo('2026-04-27 09:00:00');
        $loan = $this->oneMonthLoan();
        $this->travelTo('2026-05-20 10:00:00');
        $this->pay($loan, 500);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $data = $this->statement($loan);
        $rows = collect($data['amortization_schedule'])->keyBy('period_number');
        $number = $loan->adjustments()->where('adjustment_type', 'extension')->value('adjustment_number');

        $this->assertSame("Replaced by period 2: extension {$number} on May 20, 2026. Closed at what was collected on it.", $rows[1]['note']);
        $this->assertSame("Replaces period 1: extension {$number} on May 20, 2026.", $rows[2]['note']);
        $this->assertStringContainsString("Period 1 was replaced by period 2: extension {$number}", $data['schedule_note']);
    }

    public function test_a_loan_with_no_extension_has_no_row_notes(): void
    {
        $data = $this->statement($this->createReleasedLoan());

        $this->assertNull($data['amortization_schedule'][0]['note']);
        $this->assertNull($data['schedule_note']);
    }

    // ── Closed loans ─────────────────────────────────────────────────────

    public function test_a_completed_loan_has_a_statement_closing_at_zero(): void
    {
        $loan = $this->createReleasedLoan();
        $this->pay($loan, 70800);
        $this->assertSame('completed', $loan->fresh()->status);

        $data = $this->statement($loan);

        $this->assertSame('Completed', $data['loan']['status_label']);
        $this->assertEquals(0, $data['summary']['outstanding_balance']);
        $this->assertEquals(0, collect($data['transactions'])->last()['principal_balance']);
        $this->assertEquals(70800, $data['transaction_totals']['debit']);
        $this->assertEquals(70800, $data['transaction_totals']['credit']);
        $this->assertEquals(0, $data['schedule_totals']['balance']);
    }

    public function test_every_closed_status_answers_with_a_statement(): void
    {
        foreach (['defaulted', 'restructured', 'void', 'rejected', 'completed'] as $status) {
            $loan = $this->createReleasedLoan();
            $this->pay($loan, 11800);
            DB::table('loans')->where('id', $loan->id)->update(['status' => $status]);

            $data = $this->statement($loan);

            $this->assertSame('release', $data['transactions'][0]['kind'], $status);
            $this->assertEquals(60000, $data['transactions'][0]['principal_balance'], $status);
        }
    }

    public function test_a_loan_never_released_has_no_lines_and_still_answers(): void
    {
        $product = LoanProduct::factory()->create();
        $loan = Loan::factory()->create([
            'borrower_id' => $this->createReleasedLoan()->borrower_id,
            'loan_product_id' => $product->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
            'status' => 'draft',
            'released_at' => null,
        ]);

        $data = $this->statement($loan);

        $this->assertSame([], $data['transactions']);
        $this->assertNull($data['loan']['released_at']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function statement(Loan $loan): array
    {
        return $this->getJson("/api/reports/statement-of-account/{$loan->id}")->assertOk()->json('data');
    }

    private function oneMonthLoan(): Loan
    {
        return $this->createReleasedLoan([
            'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly', 'interest_rate' => 3.0],
            'start_date' => now()->toDateString(),
        ]);
    }

    private function ledgerClosingPrincipal(Loan $loan): float
    {
        return (float) $this->getJson("/api/loans/{$loan->id}/ledger")->assertOk()->json('data.closing.principal');
    }

    private function pay(Loan $loan, float $amount): Repayment
    {
        return app(RepaymentService::class)->processRepayment($loan->fresh(), $amount, now()->toDateString(), $this->admin);
    }

    private function applyBalanceAdjustment(Loan $loan, float $amount): void
    {
        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => $amount],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();
    }

    /** @param array<string, mixed> $summary */
    private function paidParts(array $summary): float
    {
        return round(
            $summary['principal_paid'] + $summary['interest_paid'] + $summary['penalty_paid']
            + $summary['insurance_paid'] + $summary['build_up_paid'] + $summary['overpayment_paid'],
            2,
        );
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
