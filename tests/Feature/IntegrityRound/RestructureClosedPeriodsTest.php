<?php

namespace Tests\Feature\IntegrityRound;

use App\Models\AmortizationSchedule;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Item 17: a restructure into a new loan closes the source's partly paid
 * periods (LoanService::clearOpenSchedules(), called from
 * closeRestructuredSource()) without the marker an in-place restructure or an
 * extension sets (`closed_by_adjustment_id`, LoanAdjustmentService::
 * closeOpenSchedules()). Every rule keyed on that marker misses them. The one
 * a user sees: PersistedScheduleFigures gives a closed period no Share
 * Capital Build-Up, but the source's closed period still shows one, so its
 * schedule shows a paid period owing more than was collected.
 *
 * The test sets the source's `scb_amount` after the restructure is released,
 * so the payment before it allocates nothing to build-up and the figures stay
 * readable; the rule reads `scb_amount` at display time either way.
 *
 * Pinned, whichever marker decision 8 picks: the closed period shows no
 * build-up and its total payment is what was collected, and a
 * `periods_closed` audit row records what it was due before, as the in-place
 * path does.
 */
class RestructureClosedPeriodsTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function releaseRestructureOf(Loan $source, float $principal): Loan
    {
        $newLoanId = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
        ])->assertCreated()->json('data.id');

        $approver = User::factory()->create();
        $approver->assignRole(Role::where('name', 'admin')->firstOrFail());

        $this->patchJson("/api/loans/{$newLoanId}/submit")->assertOk();
        $this->actingAs($approver)->patchJson("/api/loans/{$newLoanId}/approve", ['approval_remarks' => 'ok'])->assertOk();
        $this->actingAs($this->admin)->patchJson("/api/loans/{$newLoanId}/release")->assertOk();

        return Loan::findOrFail($newLoanId);
    }

    public function test_the_sources_closed_partly_paid_period_carries_no_build_up(): void
    {
        $source = $this->createReleasedLoan();

        // ₱5,000 clears period 1's ₱1,800 interest and ₱3,200 of its principal.
        $this->postJson("/api/loans/{$source->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 5000,
            'method' => 'cash',
        ])->assertCreated();

        $this->releaseRestructureOf($source, 70800.00 - 5000.00);
        Loan::whereKey($source->id)->update(['scb_amount' => 100]);

        $closed = AmortizationSchedule::where('loan_id', $source->id)->sole();
        $this->assertSame('paid', $closed->status);

        $row = collect($this->getJson("/api/loans/{$source->id}/amortization-schedule")->assertOk()->json('data'))
            ->firstWhere('id', $closed->id);

        $this->assertNotNull($row);
        $this->assertEquals(0, $row['share_capital_build_up']);
        $this->assertEquals(5000, $row['total_payment']);
    }

    public function test_closing_the_sources_partly_paid_periods_is_audited(): void
    {
        $source = $this->createReleasedLoan();
        $this->postJson("/api/loans/{$source->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 5000,
            'method' => 'cash',
        ])->assertCreated();

        $this->releaseRestructureOf($source, 70800.00 - 5000.00);

        $closed = AmortizationSchedule::where('loan_id', $source->id)->sole();
        $audit = AuditLog::query()
            ->where('action', 'periods_closed')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'closing the source\'s partly paid periods writes no audit row');
        $this->assertSame($closed->id, $audit->old_values['periods'][0]['schedule_id']);
        $this->assertEquals(10000, $audit->old_values['periods'][0]['principal_due']);
    }
}
