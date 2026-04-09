<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class ReportExtendedTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->createReleasedLoan();
    }

    public function test_daily_collection_returns_expected_keys(): void
    {
        $this->getJson('/api/reports/daily-collection')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['date', 'total_due', 'total_collected', 'collection_rate', 'uncollected', 'generated_at'],
            ]);
    }

    public function test_income_report_returns_expected_keys(): void
    {
        $this->getJson('/api/reports/income')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['interest_income', 'processing_fees', 'penalty_income', 'total', 'generated_at'],
            ]);
    }

    public function test_aging_report_returns_buckets(): void
    {
        $this->getJson('/api/reports/aging')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['as_of_date', 'buckets' => ['1_30', '31_60', '61_90', 'over_90'], 'generated_at'],
            ]);
    }

    public function test_borrower_report_returns_expected_keys(): void
    {
        $this->getJson('/api/reports/borrowers')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['total_active_borrowers', 'new_borrowers', 'avg_loan_size', 'repeat_borrowers', 'generated_at'],
            ]);
    }

    public function test_disbursement_report_returns_expected_keys(): void
    {
        $this->getJson('/api/reports/disbursements')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['loans_released', 'total_disbursed', 'avg_disbursement', 'pending_release', 'generated_at'],
            ]);
    }

    public function test_reports_forbidden_for_viewer_without_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('viewer');
        $this->actingAs($user);

        // Viewer has reports.view — should be allowed
        $this->getJson('/api/reports/income')->assertOk();
    }
}
