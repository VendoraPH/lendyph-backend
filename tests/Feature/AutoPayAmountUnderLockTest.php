<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\Repayment;
use App\Services\LoanAdjustmentService;
use App\Services\RepaymentService;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Auto-pay works out each loan's amount after taking the loan's lock, as a
 * payment's own allocation does. A payment that lands between auto-pay
 * reading the periods and locking the loan is then seen, so auto-pay never
 * charges the stale full amount on top of it (which spilled into future
 * periods or share capital).
 */
class AutoPayAmountUnderLockTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_period_paid_between_the_read_and_the_lock_is_not_charged_again(): void
    {
        $loan = $this->autoPayLoan();
        $period = $loan->amortizationSchedules()->reorder('period_number')->firstOrFail();

        $slippedIn = $this->atTheLoanLock(function () use ($period): void {
            DB::table('amortization_schedules')->where('id', $period->id)->update([
                'principal_paid' => DB::raw('principal_due'),
                'interest_paid' => DB::raw('interest_due'),
                'status' => 'paid',
            ]);
        });

        $response = $this->processFor($period)->assertCreated();

        $this->assertTrue($slippedIn(), 'the other payment never landed, so this test proved nothing');
        $this->assertSame(0, $response->json('data.processed'));
        $this->assertSame(1, $response->json('data.skipped'));
        $this->assertSame([[
            'loan_id' => $loan->id,
            'loan_account_number' => $loan->loan_account_number,
            'reason' => 'Nothing was due any more once the loan was locked: another payment settled it.',
        ]], $response->json('data.skips'));
        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());
        $this->assertEquals(0, AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', '>', 1)->sum('principal_paid'), 'nothing may spill into a later period');
    }

    public function test_a_period_part_paid_between_the_read_and_the_lock_is_charged_only_what_is_left(): void
    {
        $loan = $this->autoPayLoan();
        $period = $loan->amortizationSchedules()->reorder('period_number')->firstOrFail();

        $this->atTheLoanLock(function () use ($period): void {
            DB::table('amortization_schedules')->where('id', $period->id)->update([
                'interest_paid' => DB::raw('interest_due'),
                'status' => 'partial',
            ]);
        });

        $response = $this->postJson('/api/auto-pay/process', [
            'date_from' => $period->due_date->toDateString(),
            'date_to' => $period->due_date->toDateString(),
            'include_schedule_ids' => [$period->id],
        ])->assertCreated();

        // ₱11,800 due, ₱1,800 of it paid in between: ₱10,000 left.
        $this->assertSame(1, $response->json('data.processed'));
        $this->assertEquals(10000, $response->json('data.repayments.0.amount_paid'));
        $this->assertEquals(0, AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', '>', 1)->sum('principal_paid'), 'nothing may spill into a later period');
    }

    public function test_without_a_competing_payment_the_full_amount_is_charged(): void
    {
        $loan = $this->autoPayLoan();
        $period = $loan->amortizationSchedules()->reorder('period_number')->firstOrFail();

        $response = $this->processFor($period)->assertCreated();

        $this->assertSame(1, $response->json('data.processed'));
        $this->assertSame([], $response->json('data.skips'));
        $this->assertEquals(11800, $response->json('data.repayments.0.amount_paid'));
    }

    public function test_an_overdue_period_is_charged_its_late_penalty_too_and_ends_paid(): void
    {
        // Period 1 fell due a month ago, past its 3-day grace: 2% of its
        // ₱10,000 unpaid principal is a ₱200 penalty, charged by the payment.
        $loan = $this->autoPayLoan(now()->subMonths(2)->toDateString());
        $period = $loan->amortizationSchedules()->reorder('period_number')->firstOrFail();

        $response = $this->processFor($period)->assertCreated();

        $this->assertSame(1, $response->json('data.processed'));
        $this->assertEquals(12000, $response->json('data.repayments.0.amount_paid'));

        $period->refresh();
        $this->assertSame('paid', $period->status);
        $this->assertEquals(200, $period->penalty_paid);
        $this->assertEquals(0, AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', '>', 1)->sum('principal_paid'), 'nothing may spill into a later period');
    }

    public function test_a_waived_period_is_charged_no_penalty(): void
    {
        $loan = $this->autoPayLoan(now()->subMonths(2)->toDateString());
        $period = $loan->amortizationSchedules()->reorder('period_number')->firstOrFail();

        $this->waive($loan, $period);

        $response = $this->processFor($period)->assertCreated();

        $this->assertEquals(11800, $response->json('data.repayments.0.amount_paid'));
        $period->refresh();
        $this->assertSame('paid', $period->status);
        $this->assertEquals(0, $period->penalty_paid);
    }

    public function test_a_selected_period_part_paid_in_between_is_skipped_as_changed_not_settled(): void
    {
        $loan = $this->autoPayLoan();
        $period = $loan->amortizationSchedules()->reorder('period_number')->firstOrFail();

        $this->atTheLoanLock(function () use ($period): void {
            DB::table('amortization_schedules')->where('id', $period->id)->update([
                'interest_paid' => DB::raw('interest_due'),
                'status' => 'partial',
            ]);
        });

        $response = $this->processFor($period)->assertCreated();

        $this->assertSame(0, $response->json('data.processed'));
        $this->assertSame('Changed by another payment since the preview; run the preview again.', $response->json('data.skips.0.reason'));
        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());
    }

    public function test_the_preview_quotes_the_late_penalty_and_equals_what_the_run_posts(): void
    {
        $loan = $this->autoPayLoan(now()->subMonths(2)->toDateString());
        $period = $loan->amortizationSchedules()->reorder('period_number')->firstOrFail();

        $preview = $this->previewFor($period)->assertOk()->json('data');

        $this->assertEquals(200, $preview['summary']['total_penalty']);
        $this->assertEquals(12000, $preview['summary']['total_amount']);
        $this->assertEquals([
            'loan_id' => $loan->id,
            'loan_account_number' => $loan->loan_account_number,
            'borrower_name' => $preview['loans'][0]['borrower_name'],
            'total_principal' => 10000,
            'total_interest' => 1800,
            'total_penalty' => 200,
            'total_amount' => 12000,
        ], $preview['loans'][0]);
        $this->assertEquals(0, $period->fresh()->penalty_amount, 'the preview must write nothing');

        $posted = $this->processFor($period)->assertCreated()->json('data.repayments.0.amount_paid');
        $this->assertEquals($preview['summary']['total_amount'], $posted);
    }

    public function test_the_preview_quotes_no_penalty_on_a_waived_period(): void
    {
        $loan = $this->autoPayLoan(now()->subMonths(2)->toDateString());
        $period = $loan->amortizationSchedules()->reorder('period_number')->firstOrFail();
        $this->waive($loan, $period);

        $summary = $this->previewFor($period)->assertOk()->json('data.summary');

        $this->assertEquals(0, $summary['total_penalty']);
        $this->assertEquals(11800, $summary['total_amount']);
    }

    public function test_a_loan_with_an_earlier_open_period_is_skipped_by_the_preview_and_the_run(): void
    {
        // Period 1 is still open; only period 2 is selected.
        $loan = $this->autoPayLoan();
        $period2 = $loan->amortizationSchedules()->reorder('period_number')->skip(1)->firstOrFail();
        $skip = [
            'loan_id' => $loan->id,
            'loan_account_number' => $loan->loan_account_number,
            'reason' => 'Has earlier unpaid periods; collect them first.',
        ];

        $preview = $this->previewFor($period2)->assertOk()->json('data');
        $this->assertSame([$skip], $preview['skips']);
        $this->assertSame(0, $preview['summary']['loans_count']);
        $this->assertSame([], $preview['loans']);

        $run = $this->processFor($period2)->assertCreated()->json('data');
        $this->assertSame(0, $run['processed']);
        $this->assertSame([$skip], $run['skips']);
        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());
    }

    public function test_a_failed_loan_is_named_with_its_reason(): void
    {
        $loan = $this->autoPayLoan();
        $period = $loan->amortizationSchedules()->reorder('period_number')->firstOrFail();

        // The loan closes between the run's read and its lock.
        $fired = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$fired, $loan): void {
            if ($fired || ! str_contains($query, 'from `loans`') || ! str_contains($query, 'for update')) {
                return;
            }

            $fired = true;
            DB::table('loans')->where('id', $loan->id)->update(['status' => 'completed']);
        });

        $run = $this->processFor($period)->assertCreated()->json('data');

        $this->assertTrue($fired, 'the loan never closed in between, so this test proved nothing');
        $this->assertSame(1, $run['failed']);
        $this->assertSame([[
            'loan_id' => $loan->id,
            'loan_account_number' => $loan->loan_account_number,
            'reason' => 'Another change to this loan was saved at the same time. Reload and try again.',
        ]], $run['failures']);
    }

    private function autoPayLoan(?string $startDate = null): Loan
    {
        $loan = $this->createReleasedLoan(['start_date' => $startDate ?? now()->toDateString()]);
        $loan->update([
            'auto_pay' => true,
            'cbs_reference' => 'CBS-2026-00001',
            'auto_pay_enabled_at' => now(),
            'auto_pay_enabled_by' => $this->admin->id,
        ]);

        return $loan->fresh('amortizationSchedules');
    }

    public function test_the_preview_grand_total_with_a_selected_part_paid_row_equals_what_the_run_posts(): void
    {
        // Two loans: one fully due, one whose period is part-paid and selected.
        $full = $this->autoPayLoan();
        $part = $this->autoPayLoan();
        $fullPeriod = $full->amortizationSchedules()->reorder('period_number')->firstOrFail();
        $partPeriod = $part->amortizationSchedules()->reorder('period_number')->firstOrFail();
        $partPeriod->update(['interest_paid' => 1000, 'status' => 'partial']);

        $summary = $this->previewFor($fullPeriod, [$partPeriod->id])->assertOk()->json('data.summary');

        // ₱11,800 + (₱11,800 − ₱1,000).
        $this->assertEquals(11800, $summary['total_amount']);
        $this->assertEquals(10800, $summary['partial_total']);
        $this->assertEquals(22600, $summary['grand_total']);

        $posted = $this->postJson('/api/auto-pay/process', [
            'date_from' => $fullPeriod->due_date->toDateString(),
            'date_to' => $fullPeriod->due_date->toDateString(),
            'include_schedule_ids' => [$partPeriod->id],
        ])->assertCreated()->json('data.repayments');

        $this->assertEquals($summary['grand_total'], array_sum(array_column($posted, 'amount_paid')));
    }

    public function test_the_preview_grand_total_counts_a_selected_row_s_late_penalty(): void
    {
        $loan = $this->autoPayLoan(now()->subMonths(2)->toDateString());
        $period = $loan->amortizationSchedules()->reorder('period_number')->firstOrFail();
        $period->update(['interest_paid' => 1000, 'status' => 'partial']);

        $summary = $this->previewFor($period, [$period->id])->assertOk()->json('data.summary');

        // ₱800 interest + ₱10,000 principal + 2% of ₱10,000 penalty.
        $this->assertEquals(11000, $summary['partial_total']);

        $posted = $this->postJson('/api/auto-pay/process', [
            'date_from' => $period->due_date->toDateString(),
            'date_to' => $period->due_date->toDateString(),
            'include_schedule_ids' => [$period->id],
        ])->assertCreated()->json('data.repayments.0.amount_paid');

        $this->assertEquals($summary['grand_total'], $posted);
    }

    public function test_without_a_selection_the_grand_total_is_the_total_amount(): void
    {
        $loan = $this->autoPayLoan();
        $period = $loan->amortizationSchedules()->reorder('period_number')->firstOrFail();

        $summary = $this->previewFor($period)->assertOk()->json('data.summary');

        $this->assertEquals(0, $summary['partial_total']);
        $this->assertEquals($summary['total_amount'], $summary['grand_total']);
    }

    public function test_a_selected_row_outside_the_preview_is_refused(): void
    {
        $loan = $this->autoPayLoan();
        $period = $loan->amortizationSchedules()->reorder('period_number')->firstOrFail();
        // Fully due, not part-paid: not one of the preview's partial rows.
        $later = $loan->amortizationSchedules()->reorder('period_number')->skip(1)->firstOrFail();

        $this->previewFor($period, [$period->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('include_schedule_ids.0');

        $this->previewFor($period, [$later->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('include_schedule_ids.0');
    }

    private function waive(Loan $loan, AmortizationSchedule $period): void
    {
        app(RepaymentService::class)->applyPenalties($loan->fresh(), now());
        $service = app(LoanAdjustmentService::class);
        $waiver = $service->createAdjustment($loan->fresh(), [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['schedule_ids' => [$period->id]],
        ], $this->admin);
        $service->approveAdjustment($waiver, $this->admin, 'ok');
        $service->applyAdjustment($waiver->fresh());
        $this->assertNotNull($period->fresh()->penalty_waiver_id, 'the waiver did not land, so this test proves nothing');
    }

    /**
     * @param  list<int>  $includeScheduleIds
     */
    private function previewFor(AmortizationSchedule $period, array $includeScheduleIds = []): TestResponse
    {
        return $this->getJson('/api/auto-pay/preview?'.http_build_query(array_filter([
            'date_from' => $period->due_date->toDateString(),
            'date_to' => $period->due_date->toDateString(),
            'include_schedule_ids' => $includeScheduleIds,
        ])));
    }

    private function processFor(AmortizationSchedule $period): TestResponse
    {
        return $this->postJson('/api/auto-pay/process', [
            'date_from' => $period->due_date->toDateString(),
            'date_to' => $period->due_date->toDateString(),
        ]);
    }

    /**
     * Run `$change` right after the first locking read of `loans`: a payment
     * committing between auto-pay's read of the periods and its lock.
     *
     * @return Closure(): bool
     */
    private function atTheLoanLock(Closure $change): Closure
    {
        $fired = false;

        DB::listen(function (QueryExecuted $query) use (&$fired, $change): void {
            $sql = strtolower($query->sql);

            if ($fired || ! str_contains($sql, 'from `loans`') || ! str_contains($sql, 'for update')) {
                return;
            }

            $fired = true;
            $change();
        });

        return function () use (&$fired): bool {
            return $fired;
        };
    }
}
