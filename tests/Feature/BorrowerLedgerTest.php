<?php

namespace Tests\Feature;

use App\Models\Borrower;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class BorrowerLedgerTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_ledger_returns_release_as_debit_and_payment_as_credit(): void
    {
        $loan = $this->createReleasedLoan();
        $borrowerId = $loan->borrower_id;

        // Make a payment
        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 5000,
            'method' => 'cash',
        ])->assertCreated();

        $response = $this->getJson("/api/borrowers/{$borrowerId}/ledger");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'date', 'description', 'reference', 'debit', 'credit', 'balance'],
                ],
            ]);

        $entries = $response->json('data');

        // Should have at least 2 entries: 1 release + 1 payment
        $this->assertGreaterThanOrEqual(2, count($entries));

        // First entry should be loan release (debit > 0, credit = 0)
        $releaseEntry = collect($entries)->firstWhere('debit', '>', 0);
        $this->assertNotNull($releaseEntry);
        $this->assertEquals(0, $releaseEntry['credit']);

        // Should have at least one payment (credit > 0, debit = 0)
        $paymentEntry = collect($entries)->firstWhere('credit', '>', 0);
        $this->assertNotNull($paymentEntry);
        $this->assertEquals(0, $paymentEntry['debit']);
    }

    public function test_ledger_running_balance_decreases_with_payments(): void
    {
        $loan = $this->createReleasedLoan();
        $borrowerId = $loan->borrower_id;

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 10000,
            'method' => 'cash',
        ])->assertCreated();

        $entries = $this->getJson("/api/borrowers/{$borrowerId}/ledger")->json('data');

        // The balance is principal, interest and penalty, so it opens at what
        // the release raised it to and the payment takes it down from there.
        $lastEntry = end($entries);
        $this->assertLessThan((float) $entries[0]['balance'], (float) $lastEntry['balance']);
        $this->assertEqualsWithDelta((float) $entries[0]['balance'] - 10000, (float) $lastEntry['balance'], 0.001);
    }

    public function test_empty_ledger_for_borrower_with_no_loans(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->getJson("/api/borrowers/{$borrower->id}/ledger")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
