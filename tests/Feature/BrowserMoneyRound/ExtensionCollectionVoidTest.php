<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\AmortizationSchedule;
use App\Models\LoanAdjustment;
use App\Models\LoanLedgerEntry;
use App\Models\Repayment;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The payment an extension on "pay" collected with can't be voided, whatever
 * it went to. The extension closed the period it paid; a void would refund it,
 * re-open that closed period as overdue and charge its penalty again.
 *
 * Its credit entry used to be the only link, and a collection taken wholly by
 * penalty, or only by the premium, writes no interest credit. The extension
 * now names its collection (`new_values.repayment_id`), and the void guard and
 * the ledger read that.
 */
class ExtensionCollectionVoidTest extends TestCase
{
    use BuildsReleasedLoans, OwesInsurancePremium, SetupLendyPH;

    /**
     * A fixed clock: a loan made due ten days ago (the 10th) is still within
     * one anchored cycle of its due date, so an extension moves it exactly
     * one cycle whatever the real date.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo('2026-11-20 09:00:00');
    }

    public function test_a_collection_taken_wholly_by_penalty_cannot_be_voided(): void
    {
        // One month at 3% on ₱61,234.57: ₱1,837.04 interest. A 5% penalty is
        // ₱3,061.73, so the ₱1,837.04 collection goes wholly to penalty.
        $loan = $this->releasedLoanFor(principal: 61234.57, product: ['interest_method' => 'upon_maturity', 'term' => 1, 'penalty_rate' => 5.0]);
        $loan->amortizationSchedules()->update(['due_date' => now()->subDays(10)->toDateString()]);
        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $collection = Repayment::where('loan_id', $loan->id)->sole();
        $extension = LoanAdjustment::where('loan_id', $loan->id)->where('adjustment_type', 'extension')->sole();
        $this->assertEqualsWithDelta(1837.04, (float) $collection->penalty_applied, 0.001, 'setup: all of it to penalty');
        $this->assertEqualsWithDelta(0.0, (float) $collection->interest_applied, 0.001);
        $this->assertSame(0, LoanLedgerEntry::where('loan_id', $loan->id)->where('type', 'credit')->count(), 'setup: no interest credit');
        $this->assertSame($collection->id, $extension->new_values['repayment_id']);
        $period = AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', 1)->sole();

        $this->patchJson("/api/repayments/{$collection->id}/void", ['void_reason' => 'Keyed in error'])
            ->assertStatus(422)
            ->assertJsonPath('errors.repayment.0', "This payment was collected when the loan was extended ({$extension->adjustment_number}), and the extension closed the period it paid, so it cannot be voided. Use a balance adjustment to correct the loan instead.");

        $this->assertSame('posted', $collection->fresh()->status);
        $this->assertSame($period->only(['status', 'penalty_amount', 'penalty_paid', 'closed_by_adjustment_id']), $period->fresh()->only(['status', 'penalty_amount', 'penalty_paid', 'closed_by_adjustment_id']));

        $row = collect($this->getJson("/api/loans/{$loan->id}/ledger")->assertOk()->json('data.rows'))->firstWhere('repayment_id', $collection->id);
        $this->assertSame('extension_payment', $row['kind']);
        $this->assertSame($extension->id, $row['loan_adjustment_id']);
    }

    public function test_a_collection_that_took_only_the_premium_cannot_be_voided(): void
    {
        $loan = $this->loanOwingPremium(product: ['interest_method' => 'upon_maturity', 'term' => 1]);
        // The interest already paid, so the collection is the ₱400 premium owed.
        $loan->amortizationSchedules()->update(['interest_paid' => 1800, 'status' => 'partial']);
        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $collection = Repayment::where('loan_id', $loan->id)->sole();
        $this->assertEqualsWithDelta(400.0, (float) $collection->insurance_applied, 0.001, 'setup: only the premium');
        $this->assertSame(0, $collection->allocations()->count());

        $this->patchJson("/api/repayments/{$collection->id}/void", ['void_reason' => 'Keyed in error'])->assertStatus(422);

        $this->assertSame('posted', $collection->fresh()->status);
        $this->assertEqualsWithDelta(0.0, (float) $loan->fresh()->insurance_remaining_balance, 0.001);
    }

    public function test_a_payment_on_an_open_period_can_still_be_voided(): void
    {
        $loan = $this->releasedLoanFor(principal: 61234.57);
        $repayment = $this->payLoan($loan, 5000.37);

        $this->patchJson("/api/repayments/{$repayment->id}/void", ['void_reason' => 'Keyed in error'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided');
    }
}
