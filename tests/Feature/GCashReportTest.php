<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class GCashReportTest extends TestCase
{
    use SetupLendyPH;

    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        GCashTier::create(['min_amount' => 1, 'max_amount' => 1500, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);
    }

    public function test_income_report_excludes_pending_transactions(): void
    {
        // Two paid Cash Ins (₱20 charge each) → counted.
        // One pending Cash In (₱20 charge) → excluded.
        // One Cash Out (₱15 charge) → counted.
        GCashTransaction::factory()->create([
            'reference_no' => 'GC-001',
            'transaction_date' => now(),
            'type' => 'cash_in', 'amount' => 1000, 'charge_amount' => 20, 'total_amount' => 1020,
            'status' => 'paid',
            'borrower_id' => $this->borrower->id, 'transactor_user_id' => $this->admin->id,
        ]);
        GCashTransaction::factory()->create([
            'reference_no' => 'GC-002',
            'transaction_date' => now(),
            'type' => 'cash_in', 'amount' => 1000, 'charge_amount' => 20, 'total_amount' => 1020,
            'status' => 'paid',
            'borrower_id' => $this->borrower->id, 'transactor_user_id' => $this->admin->id,
        ]);
        GCashTransaction::factory()->create([
            'reference_no' => 'GC-003',
            'transaction_date' => now(),
            'type' => 'cash_in', 'amount' => 1000, 'charge_amount' => 20, 'total_amount' => 1020,
            'status' => 'pending',
            'borrower_id' => $this->borrower->id, 'transactor_user_id' => $this->admin->id,
        ]);
        GCashTransaction::factory()->create([
            'reference_no' => 'GC-004',
            'transaction_date' => now(),
            'type' => 'cash_out', 'amount' => 1000, 'charge_amount' => 15, 'total_amount' => 985,
            'status' => 'completed',
            'borrower_id' => $this->borrower->id, 'transactor_user_id' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/gcash/reports/income?start_date='
            .now()->subDay()->toDateString().'&end_date='.now()->addDay()->toDateString());

        $response->assertOk();
        $this->assertEquals(55.0, (float) $response->json('data.total_income'));
        $this->assertCount(3, $response->json('data.transactions'));
    }

    public function test_income_report_requires_date_range(): void
    {
        $this->getJson('/api/gcash/reports/income')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date', 'end_date']);
    }

    public function test_pending_report_lists_only_pending_cash_ins(): void
    {
        GCashTransaction::factory()->create([
            'reference_no' => 'GC-P01',
            'transaction_date' => now()->subDays(3),
            'type' => 'cash_in', 'amount' => 1000, 'charge_amount' => 20, 'total_amount' => 1020,
            'status' => 'pending',
            'borrower_id' => $this->borrower->id, 'transactor_user_id' => $this->admin->id,
        ]);
        GCashTransaction::factory()->create([
            'reference_no' => 'GC-P02',
            'transaction_date' => now(),
            'type' => 'cash_in', 'amount' => 1000, 'charge_amount' => 20, 'total_amount' => 1020,
            'status' => 'paid',
            'borrower_id' => $this->borrower->id, 'transactor_user_id' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/gcash/reports/pending');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending');

        $this->assertIsInt($response->json('data.0.days_pending'));
        $this->assertGreaterThanOrEqual(2, $response->json('data.0.days_pending'));
    }
}
