<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class ReportTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_statement_of_account(): void
    {
        $loan = $this->createReleasedLoan();

        // Make a payment first
        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 5000,
            'method' => 'cash',
        ]);

        $response = $this->getJson("/api/reports/statement-of-account/{$loan->id}");

        $response->assertOk()
            ->assertJsonStructure(['data' => [
                'loan', 'borrower', 'transactions', 'amortization_schedule', 'summary',
            ]]);

        $this->assertCount(1, $response->json('data.transactions'));
        $this->assertGreaterThan(0, $response->json('data.summary.total_paid'));
    }

    public function test_subsidiary_ledger(): void
    {
        $loan = $this->createReleasedLoan();

        $response = $this->getJson("/api/reports/subsidiary-ledger/{$loan->borrower->id}");

        $response->assertOk()
            ->assertJsonStructure(['data' => ['borrower', 'loans', 'totals']])
            ->assertJsonCount(1, 'data.loans');
    }

    public function test_list_of_releases(): void
    {
        $this->createReleasedLoan();

        $response = $this->getJson('/api/reports/releases');

        $response->assertOk()
            ->assertJsonStructure(['data']);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_list_of_releases_with_date_filter(): void
    {
        $this->createReleasedLoan();

        // Future date should return empty
        $this->getJson('/api/reports/releases?date_from=2099-01-01')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_list_of_repayments(): void
    {
        $loan = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 1000,
            'method' => 'cash',
        ]);

        $response = $this->getJson('/api/reports/repayments');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_list_of_repayments_with_branch_filter(): void
    {
        $loan = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 1000,
            'method' => 'cash',
        ]);

        $this->getJson("/api/reports/repayments?branch_id={$this->branch->id}")
            ->assertOk();

        // Non-existent branch
        $this->getJson('/api/reports/repayments?branch_id=9999')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_due_past_due(): void
    {
        $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(2)->toDateString()]);

        $response = $this->getJson('/api/reports/due-past-due');

        $response->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_loan_balance_summary(): void
    {
        $this->createReleasedLoan();

        $response = $this->getJson('/api/reports/loan-balance-summary');

        $response->assertOk()
            ->assertJsonStructure(['data' => [
                'portfolio', 'outstanding', 'overdue', 'by_branch',
            ]]);

        $this->assertGreaterThan(0, $response->json('data.portfolio.loan_count'));
        $this->assertGreaterThan(0, $response->json('data.portfolio.total_released'));
    }
}
