<?php

namespace Tests\Feature\FzReports;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Member Report's Avg Loan Size honours the date range: the average
 * principal of the loans released in it (round fz).
 */
class MemberReportAverageLoanSizeTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->released(10000, '2026-02-28 23:30:00');
        $this->released(20000, '2026-03-01 00:10:00');
        $this->released(40000, '2026-03-31 23:50:00');
        $this->released(90000, '2026-04-01 00:05:00');
    }

    public function test_the_average_is_of_the_loans_released_in_the_range(): void
    {
        $data = $this->getJson('/api/reports/borrowers?date_from=2026-03-01&date_to=2026-03-31')->assertOk()->json('data');

        $this->assertEquals(30000, $data['avg_loan_size']);
    }

    public function test_a_range_with_no_release_reads_zero(): void
    {
        $data = $this->getJson('/api/reports/borrowers?date_from=2025-01-01&date_to=2025-01-31')->assertOk()->json('data');

        $this->assertEquals(0, $data['avg_loan_size']);
    }

    public function test_with_no_range_it_covers_every_released_loan(): void
    {
        $this->assertEquals(40000, $this->getJson('/api/reports/borrowers')->assertOk()->json('data.avg_loan_size'));
    }

    private function released(float $principal, string $releasedAt): void
    {
        $loan = $this->createReleasedLoan(['principal_amount' => $principal]);
        DB::table('loans')->where('id', $loan->id)->update(['released_at' => $releasedAt]);
    }
}
