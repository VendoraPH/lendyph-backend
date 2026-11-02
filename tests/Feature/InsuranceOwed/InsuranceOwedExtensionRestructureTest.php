<?php

namespace Tests\Feature\InsuranceOwed;

use App\Models\Loan;
use App\Models\Repayment;
use App\Models\Role;
use App\Models\User;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Extension ("pay") collects the premium owed first in the same payment, on
 * top of the interest, so the interest is still settled. A restructure never
 * capitalises the premium: it is not part of the principal the new loan may
 * take, and it carries over to the new loan, still owed, still interest-free.
 */
class InsuranceOwedExtensionRestructureTest extends TestCase
{
    use OwesInsurancePremium, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_an_extension_that_pays_interest_also_collects_the_owed_premium_first(): void
    {
        // One-month upon-maturity loan: ₱60,000 principal, ₱1,800 interest.
        $loan = $this->loanOwingPremium(['interest_method' => 'upon_maturity', 'term' => 1]);
        $loan->amortizationSchedules()->update(['due_date' => now()->addWeek()->toDateString(), 'status' => 'pending']);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $repayment = Repayment::where('loan_id', $loan->id)->firstOrFail();

        $this->assertEqualsWithDelta(1800.00 + self::OWED, (float) $repayment->amount_paid, 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $repayment->insurance_applied, 0.001);
        $this->assertEqualsWithDelta(1800.00, (float) $repayment->interest_applied, 0.001);
        $this->assertEqualsWithDelta(0.00, $this->owed($loan), 0.001);

        // The old interest was settled in full: only the fresh cycle carries.
        $this->assertEqualsWithDelta(1800.00, (float) $loan->fresh()->amortizationSchedules()->first()->interest_due, 0.001);
    }

    public function test_a_restructure_cannot_capitalise_the_owed_premium(): void
    {
        $source = $this->loanOwingPremium();

        // ₱70,800 of principal and interest is the most the new loan may take.
        $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => self::SCHEDULE_TOTAL + self::OWED,
            'start_date' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('principal_amount');
    }

    public function test_a_restructure_carries_the_owed_premium_to_the_new_loan_without_writing_it_off(): void
    {
        $source = $this->loanOwingPremium();

        $newId = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => self::SCHEDULE_TOTAL,
            'start_date' => now()->toDateString(),
            // Today's code calls ₱400 a shortfall and wants a reason for it.
            'remarks' => 'Restructure of principal and interest',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/loans/{$newId}/submit")->assertOk();
        $approver = User::factory()->create();
        $approver->assignRole(Role::where('name', 'admin')->first());
        $this->actingAs($approver);
        $this->patchJson("/api/loans/{$newId}/approve", ['approval_remarks' => 'ok'])->assertOk();
        $this->actingAs($this->admin);
        $this->patchJson("/api/loans/{$newId}/release")->assertOk();

        $new = Loan::findOrFail($newId);
        $source->refresh();

        $this->assertSame('restructured', $source->status, 'setup: the release should close the source');
        $this->assertEqualsWithDelta(self::SCHEDULE_TOTAL, (float) $new->principal_amount, 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $new->insurance_remaining_balance, 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $source->write_off_amount, 0.001);
    }
}
