<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * An extension on "pay" collects everything the open period owes except its
 * principal (Arvin, 2026-11-03): the insurance premium owed, then the late
 * penalty owed, then the interest outstanding, in one payment. The old period
 * is then settled in full and only principal moves to the new period, which
 * owes nothing but the fresh interest (one cycle's interest per cycle moved).
 *
 * Before, "pay" collected only the interest figure: on an overdue loan the
 * penalty took its share of it first and the rest of the interest carried
 * into the new period, so "pay" acted like "defer" for the interest.
 *
 * The loan: ₱60,000 for one month at 3% upon maturity, started 27 Apr 2026
 * and due 27 May (₱1,800 interest a cycle), penalty 2% of unpaid principal
 * (₱1,200) after a 3-day grace.
 */
class ExtensionPayCollectsOwedTest extends TestCase
{
    use OwesInsurancePremium, SetupLendyPH;

    private const INTEREST = 1800.00;

    private const PENALTY = 1200.00;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** Released on 27 Apr 2026, then the clock moves to `$extendOn`. */
    private function oneMonthLoan(string $extendOn): Loan
    {
        $this->travelTo('2026-04-27 09:00:00');

        $loan = $this->createReleasedLoan([
            'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly', 'interest_rate' => 3.0],
            'principal_amount' => 60000,
            'start_date' => '2026-04-27',
        ]);

        $this->travelTo($extendOn.' 10:00:00');

        return $loan->fresh();
    }

    /** One-month upon-maturity loan owing the ₱400 premium, due `$dueDate`. */
    private function oneMonthLoanOwingPremium(string $dueDate): Loan
    {
        $loan = $this->loanOwingPremium(['interest_method' => 'upon_maturity', 'term' => 1]);
        $loan->amortizationSchedules()->update(['due_date' => $dueDate, 'status' => 'pending']);

        return $loan->fresh();
    }

    /** @return array<string, mixed> */
    private function preview(Loan $loan, string $option): array
    {
        return $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option={$option}")
            ->assertOk()
            ->json('data');
    }

    private function extend(Loan $loan, string $option): LoanAdjustment
    {
        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => $option])->assertOk();

        return LoanAdjustment::where('loan_id', $loan->id)->where('adjustment_type', 'extension')->sole();
    }

    private function newPeriod(Loan $loan): AmortizationSchedule
    {
        return $loan->fresh()->amortizationSchedules()->where('status', 'pending')->sole();
    }

    public function test_pay_on_an_overdue_loan_collects_the_penalty_and_all_the_interest(): void
    {
        // 27 May -> 27 Aug: three cycles, ₱5,400 fresh interest.
        $loan = $this->oneMonthLoan('2026-07-30');
        $oldPeriodId = $loan->amortizationSchedules()->sole()->id;

        $adjustment = $this->extend($loan, 'pay');

        $repayment = Repayment::where('loan_id', $loan->id)->sole();
        $this->assertEqualsWithDelta(self::PENALTY + self::INTEREST, (float) $repayment->amount_paid, 0.001);
        $this->assertEqualsWithDelta(self::PENALTY, (float) $repayment->penalty_applied, 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $repayment->interest_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $repayment->principal_applied, 0.001);

        // The old period is settled in full: everything it was due except the
        // principal that moved on was paid.
        $old = AmortizationSchedule::findOrFail($oldPeriodId);
        $this->assertSame('paid', $old->status);
        $this->assertSame($adjustment->id, $old->closed_by_adjustment_id);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $old->interest_paid, 0.001);
        $this->assertEqualsWithDelta((float) $old->interest_due, (float) $old->interest_paid, 0.001);
        $this->assertEqualsWithDelta(self::PENALTY, (float) $old->penalty_paid, 0.001);
        $this->assertEqualsWithDelta((float) $old->penalty_amount, (float) $old->penalty_paid, 0.001);

        // Only principal moves; the new period owes the fresh interest alone.
        $new = $this->newPeriod($loan);
        $this->assertSame('2026-08-27', $new->due_date->toDateString());
        $this->assertEqualsWithDelta(60000.00, (float) $new->principal_due, 0.001);
        $this->assertEqualsWithDelta(3 * self::INTEREST, (float) $new->interest_due, 0.001);
        $this->assertEqualsWithDelta(60000.00 + 3 * self::INTEREST, (float) $new->total_due, 0.001);

        $values = $adjustment->new_values;
        $this->assertSame(3, $values['cycles']);
        $this->assertEqualsWithDelta(0.00, (float) $values['carry_interest'], 0.001);
        $this->assertEqualsWithDelta(3 * self::INTEREST, (float) $values['fresh_interest'], 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $values['interest_paid'], 0.001);
        $this->assertEqualsWithDelta(self::PENALTY, (float) $values['penalty_paid'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $values['insurance_paid'], 0.001);
        $this->assertEqualsWithDelta(self::PENALTY + self::INTEREST, (float) $values['amount_collected'], 0.001);
        $this->assertSame($repayment->id, $values['repayment_id']);
    }

    public function test_pay_with_premium_owed_collects_the_premium_the_penalty_and_all_the_interest(): void
    {
        $this->travelTo('2026-11-20 09:00:00');
        // Due ten days ago: past grace, one cycle to 10 Dec.
        $loan = $this->oneMonthLoanOwingPremium(now()->subDays(10)->toDateString());

        $adjustment = $this->extend($loan, 'pay');

        $repayment = Repayment::where('loan_id', $loan->id)->sole();
        $this->assertEqualsWithDelta(self::OWED + self::PENALTY + self::INTEREST, (float) $repayment->amount_paid, 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $repayment->insurance_applied, 0.001);
        $this->assertEqualsWithDelta(self::PENALTY, (float) $repayment->penalty_applied, 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $repayment->interest_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $repayment->principal_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, $this->owed($loan), 0.001);

        $new = $this->newPeriod($loan);
        $this->assertEqualsWithDelta(60000.00, (float) $new->principal_due, 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $new->interest_due, 0.001);

        $values = $adjustment->new_values;
        $this->assertEqualsWithDelta(0.00, (float) $values['carry_interest'], 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $values['insurance_paid'], 0.001);
        $this->assertEqualsWithDelta(self::PENALTY, (float) $values['penalty_paid'], 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $values['interest_paid'], 0.001);
        $this->assertEqualsWithDelta(self::OWED + self::PENALTY + self::INTEREST, (float) $values['amount_collected'], 0.001);
    }

    public function test_pay_before_the_due_date_collects_the_interest_as_before(): void
    {
        $loan = $this->oneMonthLoan('2026-05-20');

        $adjustment = $this->extend($loan, 'pay');

        $repayment = Repayment::where('loan_id', $loan->id)->sole();
        $this->assertEqualsWithDelta(self::INTEREST, (float) $repayment->amount_paid, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $repayment->penalty_applied, 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $repayment->interest_applied, 0.001);

        $new = $this->newPeriod($loan);
        $this->assertSame('2026-06-27', $new->due_date->toDateString());
        $this->assertEqualsWithDelta(self::INTEREST, (float) $new->interest_due, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $adjustment->new_values['penalty_paid'], 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $adjustment->new_values['amount_collected'], 0.001);
    }

    public function test_the_pay_preview_shows_each_part_and_equals_the_extension_to_the_centavo(): void
    {
        $this->travelTo('2026-11-20 09:00:00');
        $loan = $this->oneMonthLoanOwingPremium(now()->subDays(10)->toDateString());

        $preview = $this->preview($loan, 'pay');

        $this->assertSame(
            ['amount' => 3400.0, 'insurance_part' => 400.0, 'penalty_part' => 1200.0, 'interest_part' => 1800.0],
            array_map('floatval', $preview['collect_now']),
        );
        $this->assertEqualsWithDelta(0.00, (float) $preview['carry_interest'], 0.001);

        $adjustment = $this->extend($loan, 'pay');
        $repayment = Repayment::where('loan_id', $loan->id)->sole();
        $new = $this->newPeriod($loan);

        $this->assertSame(
            [
                'amount' => (float) $repayment->amount_paid,
                'insurance_part' => (float) $repayment->insurance_applied,
                'penalty_part' => (float) $repayment->penalty_applied,
                'interest_part' => (float) $repayment->interest_applied,
            ],
            array_map('floatval', $preview['collect_now']),
        );
        $this->assertEquals((float) $adjustment->new_values['amount_collected'], (float) $preview['collect_now']['amount']);
        $this->assertEquals((float) $adjustment->new_values['carry_principal'], (float) $preview['carry_principal']);
        $this->assertEquals((float) $adjustment->new_values['carry_interest'], (float) $preview['carry_interest']);
        $this->assertEquals((float) $adjustment->new_values['fresh_interest'], (float) $preview['fresh_interest']);
        $this->assertEquals((float) $new->interest_due, (float) $preview['new_interest_due']);
        $this->assertEquals((float) $new->total_due, (float) $preview['new_total_due']);
        $this->assertSame($new->due_date->toDateString(), $preview['new_due_date']);
    }

    public function test_defer_collects_nothing_and_stacks_the_interest_as_before(): void
    {
        $loan = $this->oneMonthLoan('2026-07-30');

        $preview = $this->preview($loan, 'defer');
        $this->assertEquals(['amount' => 0, 'insurance_part' => 0, 'penalty_part' => 0, 'interest_part' => 0], $preview['collect_now']);

        $adjustment = $this->extend($loan, 'defer');

        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());
        $new = $this->newPeriod($loan);
        $this->assertEqualsWithDelta(self::INTEREST + 3 * self::INTEREST, (float) $new->interest_due, 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, (float) $adjustment->new_values['carry_interest'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $adjustment->new_values['interest_paid'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $adjustment->new_values['penalty_paid'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $adjustment->new_values['amount_collected'], 0.001);
        $this->assertNull($adjustment->new_values['repayment_id']);
    }

    public function test_the_ledger_and_the_summary_agree_after_a_pay_extension(): void
    {
        $loan = $this->oneMonthLoan('2026-07-30');
        $this->extend($loan, 'pay');

        $repayment = Repayment::where('loan_id', $loan->id)->sole();
        $ledger = $this->getJson("/api/loans/{$loan->id}/ledger")->assertOk()->json('data');
        $summary = $this->getJson("/api/loans/{$loan->id}/summary")->assertOk()->json('data');

        $payment = collect($ledger['rows'])->firstWhere('repayment_id', $repayment->id);
        $this->assertNotNull($payment, 'the collection has a ledger row');
        $this->assertEqualsWithDelta(self::PENALTY, $payment['penalty'], 0.001);
        $this->assertEqualsWithDelta(self::INTEREST, $payment['interest'], 0.001);

        $last = end($ledger['rows']);
        $this->assertEqualsWithDelta($summary['outstanding_principal'], $last['balances']['principal'], 0.001);
        $this->assertEqualsWithDelta($summary['outstanding_interest'], $last['balances']['interest'], 0.001);
        $this->assertEqualsWithDelta($summary['outstanding_penalty'], $last['balances']['penalty'], 0.001);
        $this->assertEqualsWithDelta(60000.00, (float) $summary['outstanding_principal'], 0.001);
        $this->assertEqualsWithDelta(3 * self::INTEREST, (float) $summary['outstanding_interest'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $summary['outstanding_penalty'], 0.001);
    }

    public function test_the_extension_collection_cannot_be_voided(): void
    {
        $loan = $this->oneMonthLoan('2026-07-30');
        $this->extend($loan, 'pay');

        $repayment = Repayment::where('loan_id', $loan->id)->sole();

        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Keyed in error'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('repayment');

        $this->assertNull($repayment->fresh()->voided_at);
    }
}
