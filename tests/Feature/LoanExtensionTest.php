<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class LoanExtensionTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function makeUponMaturityLoan(?array $overrides = []): Loan
    {
        return $this->createReleasedLoan(array_merge([
            'product' => [
                'interest_method' => 'upon_maturity',
                'term' => 1,
                'frequency' => 'monthly',
                'interest_rate' => 3.0,
            ],
            'principal_amount' => 60000,
            'start_date' => '2026-04-27',
        ], $overrides));
    }

    public function test_it_extends_an_upon_maturity_loan_by_one_month(): void
    {
        $loan = $this->makeUponMaturityLoan();
        $originalDueDate = $loan->amortizationSchedules->first()->due_date->toDateString();
        $expectedNewDueDate = Carbon::parse($originalDueDate)->copy()->addMonth()->toDateString();

        $response = $this->postJson("/api/loans/{$loan->id}/extend", [
            'interest_option' => 'defer',
            'remarks' => 'Borrower needs more time',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.id', $loan->id);

        $loan->refresh()->load('amortizationSchedules');

        $this->assertSame('released', $loan->status);
        $this->assertSame($expectedNewDueDate, $loan->maturity_date->toDateString());
        $this->assertSame(2, $loan->term);

        $this->assertCount(1, $loan->amortizationSchedules);
        $newSchedule = $loan->amortizationSchedules->first();
        $this->assertSame($expectedNewDueDate, $newSchedule->due_date->toDateString());
        $this->assertEqualsWithDelta(60000.00, (float) $newSchedule->principal_due, 0.01);
        // Carry interest 1800 (60000 * 3%) + fresh interest 1800 = 3600
        $this->assertEqualsWithDelta(3600.00, (float) $newSchedule->interest_due, 0.01);
        $this->assertEqualsWithDelta(63600.00, (float) $newSchedule->total_due, 0.01);
        $this->assertSame('pending', $newSchedule->status);

        $adj = LoanAdjustment::where('loan_id', $loan->id)->first();
        $this->assertNotNull($adj);
        $this->assertSame('extension', $adj->adjustment_type);
        $this->assertSame('applied', $adj->status);
        $this->assertNotNull($adj->applied_at);
        $this->assertSame('Borrower needs more time', $adj->remarks);
        $this->assertSame($originalDueDate, $adj->old_values['open_schedule_due_date']);
        $this->assertSame($expectedNewDueDate, $adj->new_values['new_due_date']);
    }

    public function test_it_carries_over_only_unpaid_interest_when_partial_payment_recorded(): void
    {
        $loan = $this->makeUponMaturityLoan();

        $schedule = $loan->amortizationSchedules->first();
        $schedule->update([
            'interest_paid' => 900,
            'status' => 'partial',
        ]);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        $loan->refresh()->load('amortizationSchedules');
        $newSchedule = $loan->amortizationSchedules->first();

        // Carry interest 900 (1800 - 900) + fresh interest 1800 = 2700
        $this->assertEqualsWithDelta(2700.00, (float) $newSchedule->interest_due, 0.01);
        $this->assertEqualsWithDelta(60000.00, (float) $newSchedule->principal_due, 0.01);
    }

    public function test_defer_stacks_the_outstanding_interest_onto_the_fresh_cycle(): void
    {
        // The behaviour the team described: ₱1,800 already due, extend, another
        // ₱1,800 accrues, so the new period owes ₱3,600 and nothing is collected.
        $loan = $this->makeUponMaturityLoan();

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])
            ->assertOk();

        $newSchedule = $loan->fresh()->amortizationSchedules()->first();

        $this->assertEqualsWithDelta(3600.00, (float) $newSchedule->interest_due, 0.01);
        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count(), 'defer must not collect anything');

        $adj = LoanAdjustment::where('loan_id', $loan->id)->first();
        $this->assertSame('defer', $adj->new_values['interest_option']);
        $this->assertEqualsWithDelta(0.0, (float) $adj->new_values['interest_paid'], 0.01);
    }

    public function test_pay_collects_the_outstanding_interest_so_only_fresh_interest_carries(): void
    {
        $loan = $this->makeUponMaturityLoan();

        // Keep the period current. processRepayment applies penalties to
        // overdue schedules before allocating, and allocation runs
        // penalty → interest → principal, so an overdue loan would spend part
        // of this payment on the penalty and leave interest behind. That case
        // is covered separately below; here we want the clean path.
        $loan->amortizationSchedules()->update([
            'due_date' => now()->addWeek()->toDateString(),
            'status' => 'pending',
        ]);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])
            ->assertOk();

        $newSchedule = $loan->fresh()->amortizationSchedules()->first();

        // The ₱1,800 already due was settled, so only the fresh cycle remains.
        $this->assertEqualsWithDelta(1800.00, (float) $newSchedule->interest_due, 0.01);

        $repayment = Repayment::where('loan_id', $loan->id)->first();
        $this->assertNotNull($repayment, 'pay must record the interest collection');
        $this->assertEqualsWithDelta(1800.00, (float) $repayment->amount_paid, 0.01);

        $adj = LoanAdjustment::where('loan_id', $loan->id)->first();
        $this->assertSame('pay', $adj->new_values['interest_option']);
        $this->assertEqualsWithDelta(1800.00, (float) $adj->new_values['interest_paid'], 0.01);
    }

    public function test_pay_on_an_overdue_loan_settles_the_penalty_first_so_some_interest_carries(): void
    {
        // Documented, inherited behaviour rather than a bug introduced here:
        // "Pay Outstanding Interest" collects the interest figure the dialog
        // shows, but processRepayment applies penalties to overdue schedules
        // and allocates penalty → interest → principal. On an overdue loan the
        // penalty is taken out of that amount first, so interest is only
        // partly settled and the remainder carries into the new period.
        //
        // The client-side version behaved identically. Pinning it here so a
        // future change to the allocation order is visible rather than silent.
        $loan = $this->makeUponMaturityLoan();   // fixture is already overdue

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])
            ->assertOk();

        $newSchedule = $loan->fresh()->amortizationSchedules()->first();

        $this->assertNotNull(Repayment::where('loan_id', $loan->id)->first());
        $this->assertGreaterThan(
            1800.00,
            (float) $newSchedule->interest_due,
            'an overdue loan should carry the interest the penalty consumed',
        );
    }

    public function test_the_interest_option_is_required(): void
    {
        $loan = $this->makeUponMaturityLoan();

        $this->postJson("/api/loans/{$loan->id}/extend")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('interest_option');

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'sometimes'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('interest_option');
    }

    public function test_a_failed_extension_does_not_leave_the_interest_payment_behind(): void
    {
        // The reason collection moved server-side. Previously the client posted
        // the repayment and then called extend; a failure in between left the
        // borrower charged with no extension, and the guard against re-charging
        // was a useRef that reset whenever the dialog was reopened.
        $loan = $this->makeUponMaturityLoan();

        // Fail at the rebuild step, which runs after the repayment is written
        // and before the transaction commits — precisely the window that used
        // to leave an orphaned payment.
        AmortizationSchedule::creating(function () {
            throw new RuntimeException('forced failure after the interest was collected');
        });

        try {
            $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay']);
        } catch (RuntimeException) {
            // expected — the point is what survives in the database
        }

        $this->assertSame(
            0,
            Repayment::where('loan_id', $loan->id)->count(),
            'the repayment must roll back with the failed extension',
        );
    }

    public function test_a_one_month_loan_can_be_extended_repeatedly(): void
    {
        // Each extension bumps `term`, so gating on the stored value would let
        // a loan be extended exactly once and then lock it out. Eligibility
        // reads the original term instead.
        $loan = $this->makeUponMaturityLoan();

        foreach ([2, 3, 4] as $expectedTerm) {
            $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer', 'remarks' => 'roll forward'])
                ->assertOk();

            $this->assertSame($expectedTerm, $loan->fresh()->term);
        }

        $this->assertTrue($loan->fresh()->isOneMonthTerm());
    }

    public function test_original_term_survives_extensions(): void
    {
        $loan = $this->makeUponMaturityLoan();

        $this->assertSame(1, $loan->originalTerm());

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();
        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        $loan->refresh();

        $this->assertSame(3, $loan->term, 'term should have rolled forward twice');
        $this->assertSame(1, $loan->originalTerm(), 'the original term must not drift');
    }

    public function test_extension_eligibility_is_exposed_on_the_loan_resource(): void
    {
        $eligible = $this->makeUponMaturityLoan();
        $ineligible = $this->createReleasedLoan(); // monthly / term 6

        $this->getJson("/api/loans/{$eligible->id}")
            ->assertOk()
            ->assertJsonPath('data.is_one_month_term', true);

        $this->getJson("/api/loans/{$ineligible->id}")
            ->assertOk()
            ->assertJsonPath('data.is_one_month_term', false);
    }

    public function test_it_rejects_loan_whose_term_is_not_one_month_with_422(): void
    {
        // Default test product is monthly / term 6 — right frequency, wrong term.
        $loan = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('term');
    }

    public function test_it_extends_a_plain_monthly_one_month_loan_regardless_of_product(): void
    {
        // Extension is gated on term, not on the product being upon-maturity:
        // a straight-interest monthly loan with a one-month term qualifies.
        $loan = $this->createReleasedLoan([
            'product' => [
                'interest_method' => 'straight',
                'frequency' => 'monthly',
                'term' => 1,
                'interest_rate' => 3.0,
            ],
            'principal_amount' => 60000,
            'start_date' => '2026-04-27',
        ]);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer', 'remarks' => 'one-month monthly loan'])
            ->assertOk();

        $this->assertSame(2, $loan->fresh()->term);
    }

    public function test_it_rejects_a_daily_loan_that_merely_runs_about_a_month(): void
    {
        // `term` is days here, not months — 30 daily periods is not a one-month term.
        $loan = $this->createReleasedLoan([
            'product' => [
                'interest_method' => 'straight',
                'frequency' => 'daily',
                'term' => 30,
                'interest_rate' => 3.0,
            ],
            'principal_amount' => 60000,
            'start_date' => '2026-04-27',
        ]);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('term');
    }

    public function test_it_rejects_loan_in_completed_status_with_422(): void
    {
        $loan = $this->makeUponMaturityLoan();
        $loan->update(['status' => 'completed']);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])
            ->assertUnprocessable();
    }

    public function test_it_rejects_user_without_loans_extend_permission_with_403(): void
    {
        $loan = $this->makeUponMaturityLoan();

        $cashier = User::factory()->create();
        $cashier->assignRole(Role::where('name', 'cashier')->first());
        $this->actingAs($cashier);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])
            ->assertForbidden();
    }

    public function test_it_returns_loan_resource_shape_matching_show_endpoint(): void
    {
        $loan = $this->makeUponMaturityLoan();

        $extendResponse = $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();
        $showResponse = $this->getJson("/api/loans/{$loan->id}")->assertOk();

        $this->assertEqualsCanonicalizing(
            array_keys($showResponse->json('data')),
            array_keys($extendResponse->json('data')),
        );
    }

    public function test_it_writes_audit_log_via_auditable_trait_for_loan_update(): void
    {
        $loan = $this->makeUponMaturityLoan();
        $beforeCount = AuditLog::where('auditable_id', $loan->id)
            ->where('auditable_type', $loan->getMorphClass())
            ->where('action', 'updated')
            ->count();

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        $afterCount = AuditLog::where('auditable_id', $loan->id)
            ->where('auditable_type', $loan->getMorphClass())
            ->where('action', 'updated')
            ->count();

        $this->assertGreaterThan($beforeCount, $afterCount);
    }

    public function test_it_rejects_when_no_open_schedule_exists(): void
    {
        $loan = $this->makeUponMaturityLoan();
        $loan->amortizationSchedules()->update(['status' => 'paid']);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])
            ->assertUnprocessable();
    }

    public function test_it_extends_loan_with_frequency_upon_maturity_and_interest_method_straight(): void
    {
        // Reproduces the bug from Loan #3: frequency drives upon-maturity, not interest_method.
        $loan = $this->createReleasedLoan([
            'product' => [
                'interest_method' => 'straight',
                'frequency' => 'upon_maturity',
                'term' => 1,
                'interest_rate' => 3.0,
            ],
            'principal_amount' => 60000,
            'start_date' => '2026-04-27',
        ]);

        $this->postJson("/api/loans/{$loan->id}/extend", [
            'interest_option' => 'defer',
            'remarks' => 'frequency-driven upon-maturity extension',
        ])->assertOk()
            ->assertJsonPath('data.id', $loan->id);

        $this->assertDatabaseHas('loan_adjustments', [
            'loan_id' => $loan->id,
            'adjustment_type' => 'extension',
            'status' => 'applied',
        ]);
    }
}
