<?php

namespace Tests\Feature\FzReports;

use App\Models\Loan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Due/Past Due report's Total Due includes penalty, as the Statement's
 * schedule does, and Paid and Balance stay consistent with it: Total Due less
 * Paid is the Balance, on every row and in the totals (round fz).
 *
 * The loan: ₱60,000.00 over 6 periods of ₱10,000.00 principal and ₱1,800.00
 * interest, started 3 months ago, so 3 periods are due.
 */
class DuePastDueTotalDueIncludesPenaltyTest extends TestCase
{
    use SetupLendyPH;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->loan = $this->createReleasedLoan(['start_date' => now()->subMonths(3)->subDays(5)->toDateString()]);

        DB::table('amortization_schedules')->where('loan_id', $this->loan->id)->where('period_number', 1)
            ->update(['penalty_amount' => 200, 'penalty_paid' => 50, 'principal_paid' => 1000, 'interest_paid' => 1800, 'status' => 'partial']);
        DB::table('amortization_schedules')->where('loan_id', $this->loan->id)->where('period_number', 2)
            ->update(['penalty_amount' => 121.85]);
    }

    public function test_a_row_s_total_due_includes_penalty_and_its_paid_counts_penalty_paid(): void
    {
        $rows = collect($this->report()->json('data'))->keyBy('period_number');

        $this->assertEquals(12000, $rows[1]['total_due']);
        $this->assertEquals(2850, $rows[1]['amount_paid']);
        $this->assertEquals(9150, $rows[1]['amount_remaining']);
        $this->assertEquals(11921.85, $rows[2]['total_due']);
        $this->assertEquals(0, $rows[2]['amount_paid']);
        $this->assertEquals(11921.85, $rows[2]['balance']);

        foreach ($rows as $period => $row) {
            $this->assertEquals($row['amount_remaining'], round($row['total_due'] - $row['amount_paid'], 2), "Period {$period}.");
        }
    }

    public function test_the_totals_are_the_rows_added_up(): void
    {
        $response = $this->report();
        $rows = collect($response->json('data'));
        $totals = $response->json('totals');

        $this->assertEquals(round($rows->sum('total_due'), 2), $totals['total_due']);
        $this->assertEquals(round($rows->sum('amount_paid'), 2), $totals['total_paid']);
        $this->assertEquals(round($rows->sum('amount_remaining'), 2), $totals['total_balance']);
        $this->assertEquals($totals['total_balance'], round($totals['total_due'] - $totals['total_paid'], 2));
        $this->assertEquals(
            round($totals['total_principal_due'] + $totals['total_interest_due'] + $totals['total_penalty'], 2),
            $totals['total_due'],
        );
    }

    public function test_the_export_s_total_due_is_the_row_s(): void
    {
        $rows = collect($this->report()->json('data'))->keyBy('period_number');

        $csv = $this->get('/api/reports/due-past-due/export', ['Accept' => 'application/json'])->assertOk()->streamedContent();

        $this->assertStringContainsString((string) $rows[2]['total_due'], $csv);
        $this->assertStringContainsString('11921.85', $csv);
    }

    private function report()
    {
        return $this->getJson('/api/reports/due-past-due?per_page=100')->assertOk();
    }
}
