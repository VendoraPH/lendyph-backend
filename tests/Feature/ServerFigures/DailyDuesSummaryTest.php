<?php

namespace Tests\Feature\ServerFigures;

use App\Models\Loan;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /dashboard/daily-dues `summary`: the dashboard pie's slices and the
 * collection rate, from the server. The browser summed the rows itself, and a
 * row's `amount_paid` includes penalty, so its rate could pass 100% and a
 * period whose penalty was paid looked collected in full while principal and
 * interest were still owed.
 */
class DailyDuesSummaryTest extends TestCase
{
    use SetupLendyPH;

    private const DAY = '2031-01-15';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * A released loan whose first period falls due on self::DAY, owing and
     * paid as given.
     */
    private function periodDue(float $principalDue, float $interestDue, float $principalPaid, float $interestPaid, float $penaltyPaid): Loan
    {
        $loan = $this->createReleasedLoan();

        $loan->amortizationSchedules()->reorder('period_number')->firstOrFail()->update([
            'due_date' => self::DAY,
            'principal_due' => $principalDue,
            'interest_due' => $interestDue,
            'total_due' => round($principalDue + $interestDue, 2),
            'principal_paid' => $principalPaid,
            'interest_paid' => $interestPaid,
            'penalty_amount' => $penaltyPaid,
            'penalty_paid' => $penaltyPaid,
        ]);

        return $loan;
    }

    public function test_a_period_whose_penalty_was_paid_is_collected_in_full_only_once_principal_and_interest_are(): void
    {
        $full = $this->periodDue(800, 200, 800, 200, 0);
        $penaltyPaid = $this->periodDue(800, 200, 700, 100, 200);   // 800 of 1,000 plus a 200 penalty
        $unpaid = $this->periodDue(400, 100, 0, 0, 0);

        $response = $this->getJson('/api/dashboard/daily-dues?date='.self::DAY)->assertOk();

        $this->assertSame(
            [$full->id => 'collected', $penaltyPaid->id => 'partial', $unpaid->id => 'pending'],
            collect($response->json('data'))->sortBy('loan_id')->pluck('status', 'loan_id')->all(),
        );

        $summary = $response->json('summary');
        $this->assertEquals(2500, $summary['total_due']);
        $this->assertEquals(1000, $summary['collected_in_full']);
        $this->assertEquals(800, $summary['partially_collected']);
        $this->assertEquals(1800, $summary['total_collected']);
        $this->assertEquals(700, $summary['uncollected']);
        $this->assertEquals(72.0, $summary['collection_rate']);
        $this->assertSame(['collected' => 1, 'partial' => 1, 'pending' => 1], $summary['counts']);
    }

    public function test_the_collection_rate_never_passes_100_when_penalties_were_paid(): void
    {
        $this->periodDue(800, 200, 800, 200, 300);
        $this->periodDue(400, 100, 400, 100, 150);

        $summary = $this->getJson('/api/dashboard/daily-dues?date='.self::DAY)->assertOk()->json('summary');

        $this->assertEquals(1500, $summary['collected_in_full']);
        $this->assertEquals(0, $summary['partially_collected']);
        $this->assertEquals(1500, $summary['total_collected']);
        $this->assertEquals(0, $summary['uncollected']);
        $this->assertEquals(100.0, $summary['collection_rate']);
        $this->assertSame(['collected' => 2, 'partial' => 0, 'pending' => 0], $summary['counts']);
    }
}
