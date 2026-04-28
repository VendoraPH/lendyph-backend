<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
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

        $this->postJson("/api/loans/{$loan->id}/extend")->assertOk();

        $loan->refresh()->load('amortizationSchedules');
        $newSchedule = $loan->amortizationSchedules->first();

        // Carry interest 900 (1800 - 900) + fresh interest 1800 = 2700
        $this->assertEqualsWithDelta(2700.00, (float) $newSchedule->interest_due, 0.01);
        $this->assertEqualsWithDelta(60000.00, (float) $newSchedule->principal_due, 0.01);
    }

    public function test_it_rejects_non_upon_maturity_loan_with_422(): void
    {
        $loan = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$loan->id}/extend")
            ->assertUnprocessable();
    }

    public function test_it_rejects_loan_in_completed_status_with_422(): void
    {
        $loan = $this->makeUponMaturityLoan();
        $loan->update(['status' => 'completed']);

        $this->postJson("/api/loans/{$loan->id}/extend")
            ->assertUnprocessable();
    }

    public function test_it_rejects_user_without_loans_extend_permission_with_403(): void
    {
        $loan = $this->makeUponMaturityLoan();

        $cashier = User::factory()->create();
        $cashier->assignRole(Role::where('name', 'cashier')->first());
        $this->actingAs($cashier);

        $this->postJson("/api/loans/{$loan->id}/extend")
            ->assertForbidden();
    }

    public function test_it_returns_loan_resource_shape_matching_show_endpoint(): void
    {
        $loan = $this->makeUponMaturityLoan();

        $extendResponse = $this->postJson("/api/loans/{$loan->id}/extend")->assertOk();
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

        $this->postJson("/api/loans/{$loan->id}/extend")->assertOk();

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

        $this->postJson("/api/loans/{$loan->id}/extend")
            ->assertUnprocessable();
    }
}
