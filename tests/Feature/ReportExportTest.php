<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class ReportExportTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->createReleasedLoan(['start_date' => now()->subMonths(2)->toDateString()]);
    }

    public function test_export_releases_returns_csv(): void
    {
        $response = $this->get('/api/reports/releases/export');

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Loan #', $content);
        $this->assertStringContainsString('Borrower', $content);
    }

    public function test_export_repayments_returns_csv(): void
    {
        // Record a payment first
        $loan = Loan::where('status', 'released')->first();
        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 100,
            'method' => 'cash',
        ]);

        $response = $this->get('/api/reports/repayments/export');

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Receipt #', $content);
    }

    public function test_export_due_past_due_returns_csv(): void
    {
        $response = $this->get('/api/reports/due-past-due/export');

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Due Date', $content);
    }

    public function test_export_requires_export_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        $this->actingAs($viewer);

        $this->get('/api/reports/releases/export')->assertForbidden();
    }
}
