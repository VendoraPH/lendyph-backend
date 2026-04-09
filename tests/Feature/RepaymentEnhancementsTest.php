<?php

namespace Tests\Feature;

use App\Models\Repayment;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class RepaymentEnhancementsTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_store_repayment_with_method_and_reference(): void
    {
        $loan = $this->createReleasedLoan();

        $response = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 5000,
            'method' => 'gcash',
            'reference_number' => 'GC-20260409-001',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.method', 'gcash')
            ->assertJsonPath('data.reference_number', 'GC-20260409-001');

        $this->assertDatabaseHas('repayments', [
            'method' => 'gcash',
            'reference_number' => 'GC-20260409-001',
        ]);
    }

    public function test_balance_before_and_after_are_computed(): void
    {
        $loan = $this->createReleasedLoan();

        $response = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 5000,
            'method' => 'cash',
        ]);

        $response->assertCreated();
        $data = $response->json('data');

        $this->assertGreaterThan(0, $data['balance_before']);
        $this->assertLessThanOrEqual($data['balance_before'], $data['balance_after']);
    }

    public function test_reference_number_required_for_non_cash(): void
    {
        $loan = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 5000,
            'method' => 'gcash',
            // reference_number intentionally missing
        ])->assertUnprocessable();
    }

    public function test_cash_does_not_require_reference_number(): void
    {
        $loan = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 5000,
            'method' => 'cash',
        ])->assertCreated();
    }

    public function test_global_repayments_list(): void
    {
        $loan = $this->createReleasedLoan();
        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 5000,
            'method' => 'cash',
        ]);

        $this->getJson('/api/repayments')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_global_repayments_filters_by_status(): void
    {
        $loan = $this->createReleasedLoan();
        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 5000,
            'method' => 'cash',
        ]);

        $this->getJson('/api/repayments?status=posted')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/repayments?status=voided')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_global_repayments_search_by_receipt_number(): void
    {
        $loan = $this->createReleasedLoan();
        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 5000,
            'method' => 'cash',
        ]);

        $repayment = Repayment::first();

        $this->getJson("/api/repayments?search={$repayment->receipt_number}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/repayments?search=NONEXISTENT')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
