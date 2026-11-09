<?php

namespace Tests\Feature\Accounting\LoanAccounting;

use App\Models\Branch;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /accounting/loan-accounting/summary (aging and records) and the two
 * fields GET /accounting/loans/aging gains: `count_total` and `share_percent`.
 */
class LoanAccountingSummaryTest extends TestCase
{
    use LoanAccountingTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_share_percent_is_two_decimals_rounded_half_up_and_count_total_adds_the_counts(): void
    {
        // Total ₱8.00. 0.07 / 8.00 = 0.875% and 0.01 / 8.00 = 0.125%: both
        // sit on a half, so half up reads 0.88 and 0.13.
        $loan = $this->agedLoan();
        $this->period($loan, -5, principal: 7.92);
        $this->period($loan, 10, principal: 0.07);
        $this->period($loan, 45, principal: 0.01);

        $aging = $this->getJson('/api/accounting/loans/aging')->assertOk()->json('data');
        $rows = collect($aging['rows'])->keyBy('bucket');

        $this->assertSame(800, $aging['total']);
        $this->assertSame(3, $aging['count_total']);
        $this->assertSame('99.00', $rows['current']['share_percent']);
        $this->assertSame('0.88', $rows['1_30']['share_percent']);
        $this->assertSame('0.13', $rows['31_60']['share_percent']);
        $this->assertSame('0.00', $rows['over_120']['share_percent']);
        $this->assertSame(['bucket', 'amount', 'count', 'share_percent'], array_keys($aging['rows'][0]));
    }

    public function test_an_empty_book_reads_zero_shares(): void
    {
        $aging = $this->getJson('/api/accounting/loans/aging')->assertOk()->json('data');

        $this->assertSame(0, $aging['total']);
        $this->assertSame(0, $aging['count_total']);
        $this->assertSame(array_fill(0, 6, '0.00'), array_column($aging['rows'], 'share_percent'));
    }

    public function test_the_summary_aging_is_the_aging_report(): void
    {
        $loan = $this->agedLoan();
        $this->period($loan, 3, principal: 1234.56, interest: 10);
        $this->period($loan, 95, principal: 99.99, penalty: 5);
        $asOf = Carbon::today()->subDays(1)->toDateString();

        $summary = $this->getJson("/api/accounting/loan-accounting/summary?as_of={$asOf}")->assertOk()->json('data');

        $this->assertSame($asOf, $summary['as_of']);
        $this->assertSame(
            $this->getJson("/api/accounting/loans/aging?as_of={$asOf}")->json('data'),
            $summary['aging'],
        );
    }

    public function test_records_are_todays_loan_figures_and_match_the_loans_list(): void
    {
        $late = $this->agedLoan(['insurance_remaining_balance' => 5.55]);
        $this->period($late, 20, principal: 1000, interest: 50, penalty: 2.5);
        $this->period($late, -10, principal: 1000, interest: 50);
        $grace = $this->agedLoan(['grace_period_days' => 3]);
        $this->period($grace, 2, principal: 300.33);
        $this->period($this->agedLoan(['status' => 'completed']), 20, principal: 999);

        $asOf = Carbon::today()->subDays(30)->toDateString();
        $records = $this->getJson("/api/accounting/loan-accounting/summary?as_of={$asOf}")->assertOk()->json('data.records');

        $this->assertSame([
            'as_of' => Carbon::today()->toDateString(),
            'loan_count' => 2,
            'outstanding_principal' => 230033,
            'total_outstanding' => 240838,
            'past_due_amount' => 105250,
            'past_due_count' => 1,
        ], $records);

        $totals = $this->loans()['meta']['totals'];
        $this->assertSame($totals['count'], $records['loan_count']);
        $this->assertSame($totals['outstanding_principal'], $records['outstanding_principal']);
        $this->assertSame($totals['total_outstanding'], $records['total_outstanding']);
        $this->assertSame($totals['past_due_amount'], $records['past_due_amount']);
    }

    public function test_the_summary_follows_the_callers_branches(): void
    {
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $this->period($this->agedLoan(), 5, principal: 10);
        $this->period($this->agedLoan(['branch_id' => $far->id]), 5, principal: 20);

        $this->assertSame(2, $this->getJson('/api/accounting/loan-accounting/summary')->json('data.records.loan_count'));
        $this->assertSame(2000, $this->getJson("/api/accounting/loan-accounting/summary?branch_id={$far->id}")->json('data.records.outstanding_principal'));

        $this->actingAs($this->branchUser($this->branch));

        $summary = $this->getJson('/api/accounting/loan-accounting/summary')->assertOk()->json('data');
        $this->assertSame(1, $summary['records']['loan_count']);
        $this->assertSame(1000, $summary['records']['outstanding_principal']);
        $this->assertSame(1000, $summary['aging']['total']);
        $this->getJson("/api/accounting/loan-accounting/summary?branch_id={$far->id}")->assertForbidden();
    }

    public function test_the_summary_query_is_validated(): void
    {
        $this->getJson('/api/accounting/loan-accounting/summary?as_of=2026-02-30')->assertUnprocessable();
        $this->getJson('/api/accounting/loan-accounting/summary?as_of=tomorrow')->assertUnprocessable();
        $this->getJson('/api/accounting/loan-accounting/summary?branch_id=x')->assertUnprocessable();
    }
}
