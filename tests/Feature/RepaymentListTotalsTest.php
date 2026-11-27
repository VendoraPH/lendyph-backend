<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\Repayment;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /api/repayments sends the Payment History page its totals.
 *
 * The page used to add up "Total Collected" in the browser from the rows it
 * held, adding each row's penalty to an `amount_paid` that already contains
 * it, so every peso of penalty was counted twice. `meta.totals` is the
 * server's figure instead: SUM(amount_paid) of posted and of voided payments,
 * over the request's search, loan, borrower and date filters (the status
 * filter is ignored, because the two totals are split by status already).
 */
class RepaymentListTotalsTest extends TestCase
{
    use SetupLendyPH;

    private Loan $first;

    private Loan $second;

    private Repayment $withPenalty;

    private Repayment $voided;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        // ₱60,000 at 3% flat for 6 months: ₱11,800 a month (₱10,000 + ₱1,800).
        $this->first = $this->createReleasedLoan();
        $this->second = $this->createReleasedLoan();

        // The first instalment is late with a ₱200 penalty, so ₱12,000 pays
        // ₱200 penalty + ₱1,800 interest + ₱10,000 principal.
        $schedule = $this->first->amortizationSchedules->first();
        DB::table('amortization_schedules')->where('id', $schedule->id)->update([
            'due_date' => now()->subDays(10)->toDateString(),
            'penalty_amount' => 200,
            'status' => 'overdue',
        ]);

        $id = $this->postJson("/api/loans/{$this->first->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 12000,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');
        $this->withPenalty = Repayment::findOrFail($id);

        Repayment::factory()->create([
            'loan_id' => $this->first->id,
            'payment_date' => '2026-09-01',
            'amount_paid' => 1000.10,
            'received_by' => $this->admin->id,
        ]);
        Repayment::factory()->create([
            'loan_id' => $this->second->id,
            'payment_date' => '2026-09-15',
            'amount_paid' => 2500.25,
            'received_by' => $this->admin->id,
        ]);
        $this->voided = Repayment::factory()->create([
            'loan_id' => $this->second->id,
            'payment_date' => '2026-09-15',
            'amount_paid' => 300,
            'status' => 'voided',
            'received_by' => $this->admin->id,
        ]);
    }

    public function test_the_list_sends_posted_and_voided_totals_and_never_adds_the_penalty_again(): void
    {
        $this->assertEquals(200, (float) $this->withPenalty->penalty_applied);

        $totals = $this->getJson('/api/repayments?per_page=1')->assertOk()->json('meta.totals');

        // ₱12,000 + ₱1,000.10 + ₱2,500.25. With the penalty added again it
        // would be ₱15,700.35.
        $this->assertSame(['collected' => 15500.35, 'voided' => 300, 'total_insurance_applied' => 0], $totals);
    }

    public function test_the_status_filter_narrows_the_rows_but_not_the_totals(): void
    {
        $response = $this->getJson('/api/repayments?status=voided')->assertOk();

        $this->assertSame([$this->voided->id], array_column($response->json('data'), 'id'));
        $this->assertSame(['collected' => 15500.35, 'voided' => 300, 'total_insurance_applied' => 0], $response->json('meta.totals'));
    }

    public function test_the_totals_follow_the_loan_and_borrower_filters(): void
    {
        $this->assertSame(
            ['collected' => 13000.1, 'voided' => 0, 'total_insurance_applied' => 0],
            $this->getJson("/api/repayments?loan_id={$this->first->id}")->assertOk()->json('meta.totals'),
        );

        $this->assertSame(
            ['collected' => 2500.25, 'voided' => 300, 'total_insurance_applied' => 0],
            $this->getJson("/api/repayments?borrower_id={$this->second->borrower_id}")->assertOk()->json('meta.totals'),
        );
    }

    public function test_the_totals_follow_the_date_range(): void
    {
        $this->assertSame(
            ['collected' => 3500.35, 'voided' => 300, 'total_insurance_applied' => 0],
            $this->getJson('/api/repayments?date_from=2026-09-01&date_to=2026-09-30')->assertOk()->json('meta.totals'),
        );

        $this->assertSame(
            ['collected' => 2500.25, 'voided' => 300, 'total_insurance_applied' => 0],
            $this->getJson('/api/repayments?date_from=2026-09-02&date_to=2026-09-15')->assertOk()->json('meta.totals'),
        );
    }

    public function test_the_totals_follow_the_search(): void
    {
        $this->assertSame(
            ['collected' => 0, 'voided' => 300, 'total_insurance_applied' => 0],
            $this->getJson('/api/repayments?search='.urlencode($this->voided->receipt_number))->assertOk()->json('meta.totals'),
        );

        $this->assertSame(
            ['collected' => 2500.25, 'voided' => 300, 'total_insurance_applied' => 0],
            $this->getJson('/api/repayments?search='.urlencode($this->second->loan_account_number))->assertOk()->json('meta.totals'),
        );
    }

    public function test_each_row_carries_the_first_last_name_the_search_matches(): void
    {
        $borrower = $this->second->borrower;
        $name = $borrower->first_name.' '.$borrower->last_name;

        $rows = $this->getJson('/api/repayments?search='.urlencode($name))->assertOk()->json('data');

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame($name, $row['borrower_first_last']);
        }
    }

    public function test_a_filter_no_payment_matches_gives_zero_totals(): void
    {
        $this->assertSame(
            ['collected' => 0, 'voided' => 0, 'total_insurance_applied' => 0],
            $this->getJson('/api/repayments?date_from=2020-01-01&date_to=2020-01-31')->assertOk()->json('meta.totals'),
        );
    }
}
