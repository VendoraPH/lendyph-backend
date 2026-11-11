<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\AuditLog;
use App\Models\LatePenaltySetting;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use App\Services\RepaymentService;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The late penalty is charged every month a period stays unpaid (owner's
 * answer, Arvin, 2026-11-11): `penalty_rate`% of the period's unpaid principal
 * once when grace ends, and again at each full month after its due date.
 * 1 month late is 1 charge, 3 months late is 3.
 *
 * The loan: ₱60,000 over six months at 3% straight (₱10,000 principal and
 * ₱1,800 interest a period), 2% penalty (₱200 a charge on a full period),
 * 3 days grace, started 2026-01-15. Period 1 falls due 2026-02-15 and is late
 * from 2026-02-19; period 2 falls due 2026-03-15, period 3 2026-04-15.
 *
 * A fresh database stores no switch date, so every period follows the monthly
 * rule unless a test sets one.
 */
class MonthlyLatePenaltyTest extends TestCase
{
    use SetupLendyPH;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $this->loan = $this->createReleasedLoan(['start_date' => '2026-01-15']);
    }

    public function test_one_two_and_three_months_late_charge_one_two_and_three_times(): void
    {
        $this->nightlyOn('2026-03-10');
        $this->assertPenalty(1, charged: 200, months: 1);

        // A full month after the due date is still one month late.
        $this->nightlyOn('2026-04-14');
        $this->assertPenalty(1, charged: 200, months: 1);

        $this->nightlyOn('2026-04-15');
        $this->assertPenalty(1, charged: 400, months: 2);
        $this->assertPenalty(2, charged: 200, months: 1);

        $this->nightlyOn('2026-05-15');
        $this->assertPenalty(1, charged: 600, months: 3);
        $this->assertPenalty(2, charged: 400, months: 2);
        $this->assertPenalty(3, charged: 200, months: 1);
    }

    public function test_the_nightly_job_run_twice_in_one_day_charges_once(): void
    {
        $this->nightlyOn('2026-05-15');
        $this->artisan('loans:apply-penalties')->assertSuccessful();

        $this->assertPenalty(1, charged: 600, months: 3);
        $this->assertPenalty(2, charged: 400, months: 2);
    }

    public function test_a_period_late_before_the_switch_keeps_its_charge_and_adds_one_per_full_month_after_it(): void
    {
        LatePenaltySetting::query()->create(['monthly_from' => '2026-04-10']);

        // Periods 1 and 2 were late before the switch: their one charge stays,
        // with nothing more until a full month after 10 April. Period 3 falls
        // late after it and follows the monthly rule.
        $this->nightlyOn('2026-05-05');
        $this->assertPenalty(1, charged: 200, months: 2);
        $this->assertPenalty(2, charged: 200, months: 1);
        $this->assertPenalty(3, charged: 200, months: 1);

        $this->nightlyOn('2026-05-15');
        $this->assertPenalty(1, charged: 400, months: 3);
        $this->assertPenalty(2, charged: 400, months: 2);
        $this->assertPenalty(3, charged: 200, months: 1);

        $this->nightlyOn('2026-06-15');
        $this->assertPenalty(1, charged: 600, months: 4);
        $this->assertPenalty(3, charged: 400, months: 2);
        $this->assertPenalty(4, charged: 200, months: 1);
    }

    public function test_a_partial_principal_payment_lowers_the_next_months_charge_only(): void
    {
        // ₱200 penalty, ₱1,800 interest, then ₱5,000 of period 1's principal.
        $this->payOn('2026-03-01', 7000);
        $this->assertPenalty(1, charged: 200, paid: 200, months: 1);

        // The first month stays at 2% of ₱10,000; the second is 2% of ₱5,000.
        $this->nightlyOn('2026-04-15');
        $this->assertPenalty(1, charged: 300, paid: 200, months: 2);

        $this->nightlyOn('2026-05-15');
        $this->assertPenalty(1, charged: 400, paid: 200, months: 3);
    }

    public function test_the_charge_never_drops_below_the_penalty_already_paid(): void
    {
        $this->nightlyOn('2026-03-20');
        $this->period(1)->update(['penalty_amount' => 500, 'penalty_paid' => 500]);

        $this->nightlyOn('2026-04-15');
        $this->assertPenalty(1, charged: 500, paid: 500, months: 2);

        $this->nightlyOn('2026-05-15');
        $this->assertPenalty(1, charged: 600, paid: 500, months: 3);
    }

    public function test_a_waiver_clears_the_penalty_so_far_and_the_next_full_month_charges_again(): void
    {
        $this->nightlyOn('2026-04-20');
        $this->assertPenalty(1, charged: 400, months: 2);

        $adjustment = $this->waiveOn('2026-04-20', [$this->period(1)->id]);

        $this->assertPenalty(1, charged: 0, months: 2);
        $this->assertSame($adjustment->id, $this->period(1)->penalty_waiver_id);

        $this->nightlyOn('2026-05-19');
        $this->assertPenalty(1, charged: 0, months: 3);

        $this->nightlyOn('2026-05-20');
        $this->assertPenalty(1, charged: 200, months: 3);

        $this->nightlyOn('2026-06-20');
        $this->assertPenalty(1, charged: 400, months: 4);
    }

    public function test_a_waiver_made_before_the_switch_keeps_its_period_penalty_free(): void
    {
        $this->nightlyOn('2026-04-20');
        $this->waiveOn('2026-04-20', [$this->period(1)->id]);

        // As a waiver applied before the switch was recorded: the waiver link only.
        $this->period(1)->update(['penalty_cleared_on' => null, 'penalty_cleared_to' => null]);

        $this->nightlyOn('2026-07-20');
        $this->assertPenalty(1, charged: 0);

        $this->payOn('2026-07-21', 1000);
        $this->assertPenalty(1, charged: 0);
    }

    public function test_a_void_puts_back_the_monthly_charge_the_payment_covered(): void
    {
        $this->nightlyOn('2026-04-15');

        // ₱400 penalty (two months), ₱1,800 interest and ₱10,000 principal.
        $repayment = $this->payOn('2026-04-16', 12200);
        $this->assertSame('paid', $this->period(1)->status);

        app(RepaymentService::class)->voidRepayment($repayment, 'Keyed twice', $this->admin);

        $this->assertPenalty(1, charged: 400, paid: 0, months: 2);
    }

    public function test_the_payment_preview_quotes_the_monthly_penalty_the_payment_charges(): void
    {
        // No nightly run since February: the payment charges every month itself.
        $this->travelTo(Carbon::parse('2026-05-15 10:00'));

        $preview = $this->postJson("/api/loans/{$this->loan->id}/repayments/preview", [
            'amount_paid' => 50000,
            'payment_date' => '2026-05-15',
        ])->assertOk()->json('data');

        $repayment = $this->payOn('2026-05-15', 50000);

        // 3 months on period 1, 2 on period 2 and 1 on period 3.
        $this->assertEqualsWithDelta(1200, (float) $preview['total_penalty'], 0.001);
        $this->assertEqualsWithDelta(1200, (float) $repayment->penalty_applied, 0.001);
    }

    public function test_the_auto_pay_preview_quotes_the_monthly_penalty_the_run_posts(): void
    {
        $this->loan->update([
            'auto_pay' => true,
            'cbs_reference' => 'CBS-2026-00001',
            'auto_pay_enabled_at' => now(),
            'auto_pay_enabled_by' => $this->admin->id,
        ]);

        $this->travelTo(Carbon::parse('2026-05-15 10:00'));
        $range = ['date_from' => '2026-02-15', 'date_to' => '2026-04-15'];

        $summary = $this->getJson('/api/auto-pay/preview?'.http_build_query($range))
            ->assertOk()
            ->json('data.summary');

        $this->postJson('/api/auto-pay/process', $range)->assertCreated();
        $posted = Repayment::where('loan_id', $this->loan->id)->sole();

        $this->assertEqualsWithDelta(1200, (float) $summary['total_penalty'], 0.001);
        $this->assertEqualsWithDelta(1200, (float) $posted->penalty_applied, 0.001);
    }

    public function test_the_extension_preview_quotes_the_monthly_penalty_the_extension_collects(): void
    {
        // ₱60,000 for one month at 3% upon maturity, due 27 May 2026, late
        // from 31 May: ₱1,200 a charge.
        $this->travelTo(Carbon::parse('2026-04-27 09:00'));
        $loan = $this->createReleasedLoan([
            'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly', 'interest_rate' => 3.0],
            'start_date' => '2026-04-27',
        ]);

        // Two full months after the due date: two charges.
        $this->travelTo(Carbon::parse('2026-07-28 10:00'));

        $preview = $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=pay")
            ->assertOk()
            ->json('data');

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();
        $values = LoanAdjustment::where('loan_id', $loan->id)->where('adjustment_type', 'extension')->sole()->new_values;

        $this->assertEqualsWithDelta(2400, (float) $preview['collect_now']['penalty_part'], 0.001);
        $this->assertEqualsWithDelta(2400, (float) $values['penalty_paid'], 0.001);
    }

    public function test_the_statement_and_the_api_show_the_posted_monthly_penalty_and_its_month_count(): void
    {
        $this->nightlyOn('2026-05-15');

        $statement = $this->getJson("/api/reports/statement-of-account/{$this->loan->id}")->assertOk()->json('data');
        $preview = $this->postJson("/api/loans/{$this->loan->id}/repayments/preview", [
            'amount_paid' => 600,
            'payment_date' => '2026-05-15',
        ])->assertOk()->json('data');

        $this->assertEqualsWithDelta(600, (float) $statement['amortization_schedule'][0]['penalty_amount'], 0.001);
        $this->assertEqualsWithDelta(600, (float) $preview['total_penalty'], 0.001);

        $rows = $this->getJson("/api/loans/{$this->loan->id}/amortization-schedule")->assertOk()->json('data');
        $this->assertSame([3, 2, 1, 0, 0, 0], array_column($rows, 'penalty_months'));

        $summary = $this->getJson("/api/loans/{$this->loan->id}/summary")->assertOk()->json('data');
        $this->assertSame(3, $summary['penalty_months']);
        $this->assertEqualsWithDelta(1200, (float) $summary['outstanding_penalty'], 0.001);
    }

    public function test_the_migration_stores_today_in_manila_as_the_switch_date_once_with_one_audit_row(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11 15:30', 'Asia/Manila'));
        $migration = require database_path('migrations/2026_11_11_300000_add_monthly_late_penalty.php');

        $migration->up();
        $migration->up();

        $this->assertSame('2026-11-11', LatePenaltySetting::monthlyFrom()?->toDateString());
        $this->assertSame(1, LatePenaltySetting::query()->count());

        $audit = AuditLog::where('action', 'late_penalty_monthly_switch')->sole();
        $this->assertNull($audit->user_id);
        // Periods 1 to 6 fell due from February to July: all late by November.
        $this->assertSame(['rule' => 'charged every month', 'monthly_from' => '2026-11-11', 'periods_already_late' => 6], $audit->new_values);

        // No charge is written by the migration.
        $this->assertPenalty(1, charged: 0, months: 0);
    }

    private function nightlyOn(string $date): void
    {
        $this->travelTo(Carbon::parse("{$date} 00:10"));
        $this->artisan('loans:apply-penalties')->assertSuccessful();
    }

    private function payOn(string $date, float $amount): Repayment
    {
        $this->travelTo(Carbon::parse("{$date} 09:00"));

        $id = $this->postJson("/api/loans/{$this->loan->id}/repayments", [
            'amount_paid' => $amount,
            'payment_date' => $date,
        ])->assertCreated()->json('data.id');

        return Repayment::findOrFail($id);
    }

    /** @param list<int> $scheduleIds */
    private function waiveOn(string $date, array $scheduleIds): LoanAdjustment
    {
        $this->travelTo(Carbon::parse("{$date} 10:00"));

        $id = $this->postJson("/api/loans/{$this->loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['schedule_ids' => $scheduleIds],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk()->assertJsonPath('data.status', 'applied');

        return LoanAdjustment::findOrFail($id);
    }

    private function period(int $number): AmortizationSchedule
    {
        return AmortizationSchedule::where('loan_id', $this->loan->id)->where('period_number', $number)->sole();
    }

    private function assertPenalty(int $period, float $charged, ?float $paid = null, ?int $months = null): void
    {
        $schedule = $this->period($period);

        $this->assertEqualsWithDelta($charged, (float) $schedule->penalty_amount, 0.001, "period {$period} penalty charged");

        if ($paid !== null) {
            $this->assertEqualsWithDelta($paid, (float) $schedule->penalty_paid, 0.001, "period {$period} penalty paid");
        }

        if ($months !== null) {
            $this->assertSame($months, (int) $schedule->penalty_months, "period {$period} months late");
        }
    }
}
