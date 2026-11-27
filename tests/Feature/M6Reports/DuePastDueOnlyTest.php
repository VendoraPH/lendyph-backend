<?php

namespace Tests\Feature\M6Reports;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use Carbon\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /reports/due-past-due?past_due_only=1 lists only the periods past the
 * due date plus the loan's grace, and its totals follow the same filter
 * (decision 2b, 2026-11-02). Without it the report is unchanged.
 */
class DuePastDueOnlyTest extends TestCase
{
    use SetupLendyPH;

    private Loan $late;

    private Loan $dueToday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        // Period 1 is past grace; period 2 is due but still inside grace.
        $this->late = $this->loan(grace: 3);
        $this->moveSchedule($this->late, 1, 10);
        $this->moveSchedule($this->late, 2, 2);

        // Due today: owed, never late.
        $this->dueToday = $this->loan(grace: 0);
        $this->moveSchedule($this->dueToday, 1, 0);
    }

    public function test_without_the_flag_every_due_row_is_listed(): void
    {
        $response = $this->getJson('/api/reports/due-past-due?per_page=100')->assertOk();

        $this->assertSame(3, $response->json('totals.count'));
        $this->assertSame(1, $response->json('totals.overdue_count'));
        $this->assertCount(3, $response->json('data'));

        $this->assertSame(
            $response->json('totals'),
            $this->getJson('/api/reports/due-past-due?per_page=100&past_due_only=0')->assertOk()->json('totals'),
            'past_due_only=0 is the unfiltered report.',
        );
    }

    public function test_past_due_only_lists_only_the_rows_past_grace(): void
    {
        $response = $this->getJson('/api/reports/due-past-due?per_page=100&past_due_only=1')->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($this->late->id, $rows[0]['loan_id']);
        $this->assertSame(1, $rows[0]['period_number']);
        $this->assertGreaterThan(0, $rows[0]['days_overdue']);
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_the_totals_follow_the_filter(): void
    {
        $response = $this->getJson('/api/reports/due-past-due?per_page=100&past_due_only=1')->assertOk();
        $row = $response->json('data.0');
        $summary = $this->getJson("/api/loans/{$this->late->id}/summary")->assertOk()->json('data');

        $this->assertSame(1, $response->json('totals.count'));
        $this->assertSame(1, $response->json('totals.overdue_count'));
        $this->assertEquals($row['amount_remaining'], $response->json('totals.total_balance'));
        $this->assertEquals($row['principal_due'], $response->json('totals.total_principal_due'));

        // Loan-level figures cover only the loans the filtered rows belong to.
        $this->assertEquals($summary['total_outstanding'], $response->json('totals.total_outstanding'));
        $this->assertEquals($summary['overdue_amount'], $response->json('totals.past_due_amount'));
        $this->assertEquals($summary['total_outstanding'], $row['loan_total_outstanding']);
        $this->assertEquals($summary['overdue_amount'], $row['loan_past_due_amount']);
    }

    public function test_it_combines_with_the_branch_and_date_filters(): void
    {
        $from = Carbon::today()->subDays(5)->toDateString();

        $this->getJson("/api/reports/due-past-due?past_due_only=1&date_from={$from}")
            ->assertOk()
            ->assertJsonPath('totals.count', 0)
            ->assertJsonPath('totals.past_due_amount', 0);

        $this->getJson("/api/reports/due-past-due?past_due_only=1&branch_id={$this->branch->id}")
            ->assertOk()
            ->assertJsonPath('totals.count', 1);
    }

    public function test_the_export_follows_the_flag(): void
    {
        $csv = $this->get('/api/reports/due-past-due/export?past_due_only=1')->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertCount(2, $lines, 'The header and the one late row.');
    }

    public function test_a_value_that_is_not_a_boolean_is_refused(): void
    {
        $this->getJson('/api/reports/due-past-due?past_due_only=maybe')
            ->assertStatus(422)
            ->assertJsonValidationErrors('past_due_only');
    }

    private function loan(int $grace): Loan
    {
        return $this->createReleasedLoan(['product' => ['grace_period_days' => $grace]]);
    }

    private function moveSchedule(Loan $loan, int $periodNumber, int $daysAgo): AmortizationSchedule
    {
        $schedule = $loan->amortizationSchedules()->where('period_number', $periodNumber)->firstOrFail();
        $schedule->update(['due_date' => Carbon::today()->subDays($daysAgo)->toDateString()]);

        return $schedule->fresh();
    }
}
