<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class RepaymentTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_record_payment_and_schedule_updated(): void
    {
        $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(2)->toDateString()]);
        $firstSchedule = $loan->amortizationSchedules->first();

        // Pay enough to cover first schedule's principal + interest (+ any penalty)
        $amount = (float) $firstSchedule->total_due + 500; // extra to cover potential penalty

        $response = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $amount,
            'method' => 'cash',
        ]);

        $response->assertCreated();
        $this->assertStringStartsWith('RCP-', $response->json('data.receipt_number'));
        $this->assertGreaterThan(0, $response->json('data.principal_applied'));

        // First schedule should be paid
        $firstSchedule->refresh();
        $this->assertEquals('paid', $firstSchedule->status);
    }

    public function test_record_small_payment_is_partial(): void
    {
        $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(2)->toDateString()]);

        $response = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 100,
            'method' => 'cash',
        ]);

        $response->assertCreated();
        $this->assertContains($response->json('data.payment_type'), ['partial', 'exact']);
    }

    public function test_advance_payment_covers_future_schedules(): void
    {
        $loan = $this->createReleasedLoan();
        $totalAllDue = $loan->amortizationSchedules->sum('total_due');

        // Pay 2x first schedule's amount — should cover current + future
        $firstAmount = (float) $loan->amortizationSchedules->first()->total_due;
        $payAmount = $firstAmount * 2;

        $response = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $payAmount,
            'method' => 'cash',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment_type', 'advance');
    }

    public function test_auto_close_loan_when_fully_paid(): void
    {
        $loan = $this->createReleasedLoan();
        $totalDue = $loan->amortizationSchedules->sum('total_due');

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $totalDue,
            'method' => 'cash',
        ])->assertCreated();

        $this->assertDatabaseHas('loans', [
            'id' => $loan->id,
            'status' => 'completed',
        ]);
    }

    public function test_void_repayment_reverses_balances(): void
    {
        $loan = $this->createReleasedLoan();
        $totalDue = $loan->amortizationSchedules->sum('total_due');

        // Pay full → loan closes
        $response = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $totalDue,
            'method' => 'cash',
        ]);

        $repaymentId = $response->json('data.id');
        $this->assertDatabaseHas('loans', ['id' => $loan->id, 'status' => 'completed']);

        // Void → loan reopens
        $this->patchJson("/api/repayments/{$repaymentId}/void", [
            'void_reason' => 'Duplicate entry',
        ])->assertOk()
            ->assertJsonPath('data.status', 'voided');

        $this->assertDatabaseHas('loans', ['id' => $loan->id, 'status' => 'released']);
    }

    public function test_cannot_pay_non_released_loan(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['status' => 'draft']);

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 1000,
            'method' => 'cash',
        ])->assertUnprocessable();
    }
}
