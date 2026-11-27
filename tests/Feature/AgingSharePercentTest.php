<?php

namespace Tests\Feature;

use App\Models\Loan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The aging report's `share_percent`: each bucket's part of the total overdue
 * amount, from centavos, so the screen never divides one figure by another.
 */
class AgingSharePercentTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_each_bucket_carries_its_share_of_the_total(): void
    {
        $loan = $this->createReleasedLoan();
        // ₱11,800 a period (₱10,000 principal + ₱1,800 interest).
        $this->overdue($loan, 1, daysAgo: 10);
        $this->overdue($loan, 2, daysAgo: 45, principalPaid: 5900);
        $this->overdue($loan, 3, daysAgo: 100);

        $report = $this->getJson('/api/reports/aging')->assertOk()->json('data');

        $this->assertEquals(29500, $report['total']['amount']);
        $this->assertEquals(40, $report['buckets']['1_30']['share_percent']);
        $this->assertEquals(20, $report['buckets']['31_60']['share_percent']);
        $this->assertEquals(0, $report['buckets']['61_90']['share_percent']);
        $this->assertEquals(40, $report['buckets']['over_90']['share_percent']);
        $this->assertEquals(100, $report['total']['share_percent']);
    }

    public function test_a_share_is_rounded_to_two_places(): void
    {
        $loan = $this->createReleasedLoan();
        $this->overdue($loan, 1, daysAgo: 10);
        $this->overdue($loan, 2, daysAgo: 45);
        $this->overdue($loan, 3, daysAgo: 100);

        $buckets = $this->getJson('/api/reports/aging')->assertOk()->json('data.buckets');

        $this->assertSame(33.33, $buckets['1_30']['share_percent']);
        $this->assertSame(33.33, $buckets['31_60']['share_percent']);
        $this->assertSame(33.33, $buckets['over_90']['share_percent']);
    }

    public function test_with_nothing_overdue_no_share_is_stated(): void
    {
        $this->createReleasedLoan();

        $report = $this->getJson('/api/reports/aging')->assertOk()->json('data');

        foreach ($report['buckets'] as $bucket) {
            $this->assertNull($bucket['share_percent']);
        }
        $this->assertNull($report['total']['share_percent']);
    }

    private function overdue(Loan $loan, int $period, int $daysAgo, float $principalPaid = 0): void
    {
        DB::table('amortization_schedules')
            ->where('loan_id', $loan->id)
            ->where('period_number', $period)
            ->update([
                'due_date' => now()->subDays($daysAgo)->toDateString(),
                'principal_paid' => $principalPaid,
                'status' => $principalPaid > 0 ? 'partial' : 'overdue',
            ]);
    }
}
