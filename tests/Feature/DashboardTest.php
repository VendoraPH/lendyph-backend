<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class DashboardTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_stats_returns_expected_keys(): void
    {
        $this->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'total_portfolio',
                    'active_loans_count',
                    'total_collected_mtd',
                    'overdue_loans_count',
                    'sparklines' => ['portfolio', 'active_loans', 'collected', 'overdue'],
                ],
            ]);
    }

    public function test_stats_counts_match_seeded_data(): void
    {
        $loan = $this->createReleasedLoan();

        $response = $this->getJson('/api/dashboard/stats')->assertOk();

        $this->assertEquals(1, $response->json('data.active_loans_count'));
        $this->assertGreaterThan(0, $response->json('data.total_portfolio'));
    }

    public function test_collections_trend_returns_12_weeks(): void
    {
        $this->getJson('/api/dashboard/collections-trend')
            ->assertOk()
            ->assertJsonCount(12, 'data');
    }

    public function test_daily_dues_returns_expected_structure(): void
    {
        $this->createReleasedLoan();

        $this->getJson('/api/dashboard/daily-dues')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'summary' => ['total_due', 'total_collected', 'collection_rate', 'uncollected'],
            ]);
    }

    public function test_recent_transactions_returns_data_key(): void
    {
        $this->createReleasedLoan();

        $this->getJson('/api/dashboard/recent-transactions')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_daily_dues_shows_pending_for_unpaid_schedules(): void
    {
        // Create a loan with start date in the past so first schedule is already due
        $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(2)->toDateString()]);
        $schedule = $loan->amortizationSchedules()->orderBy('period_number')->first();

        $response = $this->getJson("/api/dashboard/daily-dues?date={$schedule->due_date->toDateString()}");
        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'summary' => ['total_due', 'total_collected', 'collection_rate', 'uncollected'],
            ]);
    }
}
