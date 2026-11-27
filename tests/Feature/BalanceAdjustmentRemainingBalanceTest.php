<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Services\LoanAdjustmentService;
use App\Services\RepaymentService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * After a balance adjustment, each open period's `remaining_balance` is the
 * principal still owed once that period is paid: a running balance from the
 * loan's actual unpaid principal (every earlier adjustment and payment
 * included) down through the open periods in period order, the order a
 * payment allocates. Paid periods, and
 * the rows an extension or a restructure closed, are never rewritten.
 *
 * The loan from createReleasedLoan(): ₱60,000 over 6 monthly periods of
 * ₱10,000 principal and ₱1,800 interest.
 */
class BalanceAdjustmentRemainingBalanceTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_second_adjustment_s_remaining_balances_include_the_first(): void
    {
        $loan = $this->createReleasedLoan();

        $this->applyBalanceAdjustment($loan, -1000);
        $this->applyBalanceAdjustment($loan, -1000);

        $this->assertRunningBalanceOfOpenPeriods($loan);
        // ₱58,000 owed; the first period's ₱9,666.66 off leaves ₱48,333.34.
        $this->assertSame(4833334, $this->centavos($this->period($loan, 1)->remaining_balance));
        $this->assertSame(0, $this->centavos($this->period($loan, 6)->remaining_balance));
    }

    public function test_the_last_open_period_owes_nothing_after_it(): void
    {
        $loan = $this->createReleasedLoan();

        $this->applyBalanceAdjustment($loan, 1234.56);

        $this->assertRunningBalanceOfOpenPeriods($loan);
        $this->assertSame(0, $this->centavos($this->period($loan, 6)->remaining_balance));
    }

    public function test_a_paid_period_is_left_exactly_as_it_was(): void
    {
        $loan = $this->createReleasedLoan();
        app(RepaymentService::class)->processRepayment($loan->fresh(), 11800, now()->toDateString(), $this->admin);
        $paid = $this->period($loan, 1);
        $this->assertSame('paid', $paid->status);
        $before = $paid->getRawOriginal();

        $this->applyBalanceAdjustment($loan, -1000);

        $this->assertSame($before, $this->period($loan, 1)->getRawOriginal());
        $this->assertRunningBalanceOfOpenPeriods($loan);
    }

    public function test_rows_an_extension_closed_are_left_exactly_as_they_were(): void
    {
        $loan = $this->createReleasedLoan();
        // Part of period 1's principal paid, so the extension keeps it, closed.
        app(RepaymentService::class)->processRepayment($loan->fresh(), 5000, now()->toDateString(), $this->admin);

        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'term_extension',
            'new_values' => ['additional_terms' => 2],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        $closed = AmortizationSchedule::where('loan_id', $loan->id)->whereNotNull('closed_by_adjustment_id')->orderBy('id')->get();
        $this->assertNotEmpty($closed, 'the extension closed no row, so this test proves nothing');
        $before = $closed->map(fn (AmortizationSchedule $row): array => $row->getRawOriginal())->all();

        $this->applyBalanceAdjustment($loan, -1000);

        $this->assertSame($before, AmortizationSchedule::whereIn('id', $closed->pluck('id'))->orderBy('id')->get()
            ->map(fn (AmortizationSchedule $row): array => $row->getRawOriginal())->all());
        $this->assertRunningBalanceOfOpenPeriods($loan);
    }

    public function test_the_running_balance_follows_period_order_when_due_dates_do_not(): void
    {
        $loan = $this->createReleasedLoan();
        // Period 2 rescheduled past period 5's due date.
        $period5Due = $this->period($loan, 5)->due_date->copy()->addDays(3)->toDateString();
        AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', 2)->update([
            'due_date' => $period5Due,
            'principal_due' => 12000,
            'total_due' => 13800,
        ]);

        $this->applyBalanceAdjustment($loan, -1000);

        $this->assertRunningBalanceOfOpenPeriods($loan);
        $this->assertSame(0, $this->centavos($this->period($loan, 6)->remaining_balance));
    }

    /**
     * Each open period's remaining balance is the unpaid principal of the open
     * periods after it, to the centavo.
     */
    private function assertRunningBalanceOfOpenPeriods(Loan $loan): void
    {
        $open = AmortizationSchedule::where('loan_id', $loan->id)
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->orderBy('period_number')
            ->get();

        $balance = $open->sum(fn (AmortizationSchedule $row): int => $this->centavos($row->principal_due) - $this->centavos($row->principal_paid));

        foreach ($open as $row) {
            $balance -= $this->centavos($row->principal_due) - $this->centavos($row->principal_paid);
            $this->assertSame($balance, $this->centavos($row->remaining_balance), "period {$row->period_number}");
        }
    }

    private function applyBalanceAdjustment(Loan $loan, float $amount): void
    {
        $service = app(LoanAdjustmentService::class);
        $adjustment = $service->createAdjustment($loan->fresh(), [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => $amount],
        ], $this->admin);
        $service->approveAdjustment($adjustment, $this->admin, 'ok');

        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/apply")->assertOk();
    }

    private function period(Loan $loan, int $number): AmortizationSchedule
    {
        return AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', $number)->firstOrFail();
    }

    private function centavos(mixed $pesos): int
    {
        return (int) round((float) $pesos * 100);
    }
}
