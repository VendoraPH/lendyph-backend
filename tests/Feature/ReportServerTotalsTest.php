<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Every total the reports screen prints for the Releases list, the Repayments
 * list, the Income report, the Aging report and the Due / Past Due list comes
 * from the server, under the key the screen reads, and covers the whole
 * filtered set rather than the page in front of it. The screen only falls
 * back to adding up a page when a key is missing, so none may be.
 *
 * Each test seeds its own branch and filters on it, so nothing seeded
 * elsewhere moves the expected figures.
 */
class ReportServerTotalsTest extends TestCase
{
    use SetupLendyPH;

    private Branch $reportBranch;

    private LoanProduct $product;

    private int $periodNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->reportBranch = Branch::factory()->create();
        $this->product = LoanProduct::factory()->create(['processing_fee' => 2.0]);
    }

    public function test_releases_totals_cover_every_page(): void
    {
        foreach ([10000.10, 20000.20, 30000.30, 40000.40, 50000.50] as $i => $principal) {
            $loan = $this->releasedLoan(sprintf('2026-03-%02d 10:00:00', 10 + $i), $principal, $principal - 100);
            $this->schedule($loan, now()->addMonth()->toDateString(), principalDue: $principal, principalPaid: 1000);
        }

        $pages = $this->everyPage('releases', 'date_from=2026-03-01&date_to=2026-03-31');

        foreach ($pages as $page) {
            $page->assertJsonPath('meta.total', 5)
                ->assertJsonPath('totals.count', 5)
                ->assertJsonPath('totals.total_principal', 150001.5)
                ->assertJsonPath('totals.total_net_proceeds', 149501.5)
                ->assertJsonPath('totals.total_outstanding_balance', 145001.5);
        }
    }

    public function test_repayments_totals_cover_every_page(): void
    {
        $loan = $this->releasedLoan('2026-01-05 09:00:00', 100000, 100000);

        foreach ([[1000.10, 800.05, 150.03, 50.02], [2000.20, 1700.10, 250.05, 50.05], [1500, 1200, 300, 0], [999.99, 900, 99.99, 0], [10, 0, 0, 10]] as $i => [$paid, $principal, $interest, $penalty]) {
            $this->repayment($loan, sprintf('2026-03-%02d', 10 + $i), $paid, $principal, $interest, $penalty);
        }
        $this->repayment($loan, '2026-03-20', 5000, 5000, 0, 0, 'voided');

        foreach ($this->everyPage('repayments', 'date_from=2026-03-01&date_to=2026-03-31') as $page) {
            $page->assertJsonPath('meta.total', 5)
                ->assertJsonPath('totals.count', 5)
                ->assertJsonPath('totals.total_amount_paid', 5510.29)
                ->assertJsonPath('totals.total_principal_applied', 4600.15)
                ->assertJsonPath('totals.total_interest_applied', 800.07)
                ->assertJsonPath('totals.total_penalty_applied', 110.07);
        }
    }

    /**
     * A 0.5% fee on 201.00 is 1.005, which the report shows as 1.01. Total
     * Income has to be the three figures shown added up (10.00 + 1.01 + 0.00),
     * not the unrounded sum rounded once (11.005 → 11.00).
     */
    public function test_income_total_is_the_sum_of_the_figures_it_shows(): void
    {
        $product = LoanProduct::factory()->create(['processing_fee' => 0.5]);
        $loan = $this->releasedLoan('2026-03-10 09:00:00', 201, 201, $product);
        $this->repayment($loan, '2026-03-15', 10, 0, 10, 0);

        $data = $this->report('income', 'date_from=2026-03-01&date_to=2026-03-31')->assertOk()->json('data');

        $this->assertEquals(10, $data['interest_income']);
        $this->assertEquals(1.01, $data['processing_fees']);
        $this->assertEquals(0, $data['penalty_income']);
        $this->assertEquals(11.01, $data['total']);
    }

    public function test_income_total_matches_its_components_over_the_whole_period(): void
    {
        foreach ([60000, 30000.5, 45000.25] as $i => $principal) {
            $loan = $this->releasedLoan(sprintf('2026-03-%02d 09:00:00', 10 + $i), $principal, $principal);
            $this->repayment($loan, sprintf('2026-03-%02d', 15 + $i), 1000 + $i, 0, 900.33 + $i, 99.67);
        }

        $data = $this->report('income', 'date_from=2026-03-01&date_to=2026-03-31')->assertOk()->json('data');

        $this->assertEquals(2703.99, $data['interest_income']);
        $this->assertEquals(2700.02, $data['processing_fees']);
        $this->assertEquals(299.01, $data['penalty_income']);
        $this->assertEquals(round($data['interest_income'] + $data['processing_fees'] + $data['penalty_income'], 2), $data['total']);
        $this->assertEquals(5703.02, $data['total']);
    }

    public function test_aging_total_is_present_and_equals_its_buckets(): void
    {
        $asOf = now()->startOfDay();
        $loan = $this->releasedLoan($asOf->copy()->subYear()->toDateTimeString(), 100000, 100000);

        // 1–30, 31–60, 61–90 and over 90 days late, each partly paid.
        foreach ([10 => 1000.10, 45 => 2000.20, 75 => 3000.30, 120 => 4000.40] as $daysLate => $principal) {
            $this->schedule($loan, $asOf->copy()->subDays($daysLate)->toDateString(), principalDue: $principal, interestDue: 100, principalPaid: 0.10, penalty: 5.05);
        }

        $data = $this->report('aging', 'date_to='.$asOf->toDateString())->assertOk()->json('data');

        $this->assertEquals(10420.8, $data['total']['amount']);
        $this->assertSame(1, $data['total']['count']);
        $this->assertEquals(1105.05, $data['buckets']['1_30']['amount']);
        $this->assertEquals(4105.35, $data['buckets']['over_90']['amount']);
        $this->assertEquals(
            $data['total']['amount'],
            round(array_sum(array_column($data['buckets'], 'amount')), 2),
        );
    }

    public function test_due_past_due_totals_cover_every_page_including_paid(): void
    {
        $loan = $this->releasedLoan(now()->subYear()->toDateTimeString(), 100000, 100000);

        $rows = [
            // principal due, interest due, principal paid, interest paid, penalty
            [1000.00, 100.00, 0, 0, 0],
            [1000.00, 100.00, 250.25, 100.00, 10.10],
            [2000.50, 200.05, 1000.00, 50.50, 0],
            [1500.00, 150.00, 1499.99, 0, 5.00],
            [999.99, 99.99, 0.01, 99.99, 0],
        ];

        foreach ($rows as $i => [$principalDue, $interestDue, $principalPaid, $interestPaid, $penalty]) {
            $this->schedule(
                $loan,
                now()->subDays(40 - $i)->toDateString(),
                principalDue: $principalDue,
                interestDue: $interestDue,
                principalPaid: $principalPaid,
                interestPaid: $interestPaid,
                penalty: $penalty,
                status: $principalPaid + $interestPaid > 0 ? 'partial' : 'overdue',
            );
        }

        $period = 'date_from='.now()->subDays(60)->toDateString().'&date_to='.now()->toDateString();

        foreach ($this->everyPage('due-past-due', $period) as $page) {
            $page->assertJsonPath('meta.total', 5)
                ->assertJsonPath('totals.count', 5)
                ->assertJsonPath('totals.overdue_count', 5)
                ->assertJsonPath('totals.total_principal_due', 6500.49)
                ->assertJsonPath('totals.total_interest_due', 650.04)
                ->assertJsonPath('totals.total_penalty', 15.1)
                ->assertJsonPath('totals.total_due', 7150.53)
                ->assertJsonPath('totals.total_paid', 3000.74)
                ->assertJsonPath('totals.total_balance', 4164.89);
        }
    }

    /**
     * Pages 1 to 3 of a five-row list at two rows a page.
     *
     * @return list<TestResponse>
     */
    private function everyPage(string $path, string $query): array
    {
        $pages = [];

        foreach ([1, 2, 3] as $number) {
            $page = $this->report($path, "{$query}&per_page=2&page={$number}")->assertOk();
            $this->assertCount($number === 3 ? 1 : 2, $page->json('data'), "page {$number}");
            $pages[] = $page;
        }

        return $pages;
    }

    private function report(string $path, string $query): TestResponse
    {
        return $this->getJson("/api/reports/{$path}?{$query}&branch_id={$this->reportBranch->id}");
    }

    private function releasedLoan(string $releasedAt, float $principal, float $netProceeds, ?LoanProduct $product = null): Loan
    {
        return Loan::factory()->create([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->reportBranch->id])->id,
            'loan_product_id' => ($product ?? $this->product)->id,
            'branch_id' => $this->reportBranch->id,
            'created_by' => $this->admin->id,
            'principal_amount' => $principal,
            'net_proceeds' => $netProceeds,
            // No recorded deductions, so the Income report charges the
            // product's rate on the principal, unrounded per loan.
            'deductions' => null,
            'insurance_remaining_balance' => 0,
            'status' => 'ongoing',
            'released_at' => $releasedAt,
        ]);
    }

    private function repayment(Loan $loan, string $date, float $paid, float $principal, float $interest, float $penalty, string $status = 'posted'): Repayment
    {
        return Repayment::factory()->create([
            'loan_id' => $loan->id,
            'payment_date' => $date,
            'amount_paid' => $paid,
            'principal_applied' => $principal,
            'interest_applied' => $interest,
            'penalty_applied' => $penalty,
            'status' => $status,
            'received_by' => $this->admin->id,
        ]);
    }

    private function schedule(
        Loan $loan,
        string $dueDate,
        float $principalDue,
        float $interestDue = 0,
        float $principalPaid = 0,
        float $interestPaid = 0,
        float $penalty = 0,
        string $status = 'pending',
    ): AmortizationSchedule {
        return AmortizationSchedule::factory()->create([
            'loan_id' => $loan->id,
            'period_number' => ++$this->periodNumber,
            'due_date' => $dueDate,
            'principal_due' => $principalDue,
            'interest_due' => $interestDue,
            'total_due' => round($principalDue + $interestDue, 2),
            'principal_paid' => $principalPaid,
            'interest_paid' => $interestPaid,
            'penalty_amount' => $penalty,
            'penalty_paid' => 0,
            'status' => $status,
        ]);
    }
}
