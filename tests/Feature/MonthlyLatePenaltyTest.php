<?php

namespace Tests\Feature;

use App\Models\AmortizationPrincipalChange;
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
        // late after it and follows the monthly rule. `penalty_months` counts
        // the charges in each figure.
        $this->nightlyOn('2026-05-05');
        $this->assertPenalty(1, charged: 200, months: 1);
        $this->assertPenalty(2, charged: 200, months: 1);
        $this->assertPenalty(3, charged: 200, months: 1);

        $this->nightlyOn('2026-05-15');
        $this->assertPenalty(1, charged: 400, months: 2);
        $this->assertPenalty(2, charged: 400, months: 2);
        $this->assertPenalty(3, charged: 200, months: 1);

        $this->nightlyOn('2026-06-15');
        $this->assertPenalty(1, charged: 600, months: 3);
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

    public function test_a_waiver_clears_the_penalty_so_far_and_the_next_due_date_anniversary_charges_again(): void
    {
        $this->nightlyOn('2026-04-20');
        $this->assertPenalty(1, charged: 400, months: 2);

        $adjustment = $this->waiveOn('2026-04-20', [$this->period(1)->id]);

        $this->assertPenalty(1, charged: 0, months: 2);
        $this->assertSame($adjustment->id, $this->period(1)->penalty_waiver_id);

        // The period's monthly cycle runs on its due date, the 15th: the next
        // charge falls on 15 May, not a full month after the waiver.
        $this->nightlyOn('2026-05-14');
        $this->assertPenalty(1, charged: 0, months: 2);

        $this->nightlyOn('2026-05-15');
        $this->assertPenalty(1, charged: 200, months: 3);

        $this->nightlyOn('2026-06-15');
        $this->assertPenalty(1, charged: 400, months: 4);
    }

    public function test_a_waiver_before_the_nightly_run_forgives_the_charge_that_fell_that_day(): void
    {
        $this->nightlyOn('2026-04-14');
        $this->assertPenalty(1, charged: 200, months: 1);

        // 15 April is period 1's second month; the nightly run has not stamped it.
        $adjustment = $this->waiveOn('2026-04-15', [$this->period(1)->id], '00:05');

        $audit = AuditLog::where('action', 'penalty_waived')->where('auditable_id', $adjustment->id)->sole();
        $this->assertEqualsWithDelta(400, (float) $audit->new_values['total_waived'], 0.001);
        $this->assertPenalty(1, charged: 0, months: 2);

        $this->nightlyOn('2026-04-15');
        $this->assertPenalty(1, charged: 0, months: 2);

        $this->nightlyOn('2026-05-15');
        $this->assertPenalty(1, charged: 200, months: 3);
    }

    public function test_the_ledger_has_one_line_per_monthly_charge_on_the_day_it_fell(): void
    {
        $this->nightlyOn('2026-03-10');
        $this->assertSame([['2026-02-19', 'Late penalty, period 1', 200.0, 200.0]], $this->penaltyLines());

        $this->nightlyOn('2026-05-15');

        $this->assertSame([
            ['2026-02-19', 'Late penalty, period 1', 200.0, 200.0],
            ['2026-03-19', 'Late penalty, period 2', 200.0, 400.0],
            ['2026-04-15', 'Late penalty, period 1, month 2', 200.0, 600.0],
            ['2026-04-19', 'Late penalty, period 3', 200.0, 800.0],
            ['2026-05-15', 'Late penalty, period 1, month 3', 200.0, 1000.0],
            ['2026-05-15', 'Late penalty, period 2, month 2', 200.0, 1200.0],
        ], $this->penaltyLines());

        $this->assertLedgerClosesOnTheSummary();
    }

    public function test_the_ledger_shows_a_waiver_between_monthly_charges_and_the_charges_after_it(): void
    {
        $this->nightlyOn('2026-04-15');
        $this->waiveOn('2026-04-20', [$this->period(1)->id]);
        $this->nightlyOn('2026-05-15');

        $ledger = $this->getJson("/api/loans/{$this->loan->id}/ledger")->assertOk()->json('data');
        $lines = collect($ledger['rows'])
            ->whereIn('kind', ['penalty_charge', 'adjustment'])
            ->map(fn (array $row): array => [$row['date'], $row['kind'], (float) $row['penalty']])
            ->values()
            ->all();

        $this->assertSame([
            ['2026-02-19', 'penalty_charge', 200.0],
            ['2026-03-19', 'penalty_charge', 200.0],
            ['2026-04-15', 'penalty_charge', 200.0],
            ['2026-04-19', 'penalty_charge', 200.0],
            ['2026-04-20', 'adjustment', -400.0],
            ['2026-05-15', 'penalty_charge', 200.0],
            ['2026-05-15', 'penalty_charge', 200.0],
        ], $lines);

        $this->assertLedgerClosesOnTheSummary();
    }

    public function test_a_waiver_made_before_the_switch_keeps_its_period_penalty_free(): void
    {
        $this->nightlyOn('2026-04-20');
        $this->waiveOn('2026-04-20', [$this->period(1)->id]);

        // As a waiver applied before the switch was recorded: the waiver link only.
        $this->forgetWaiverCheckpoint(1);

        $this->nightlyOn('2026-07-20');
        $this->assertPenalty(1, charged: 0);

        $this->payOn('2026-07-21', 1000);
        $this->assertPenalty(1, charged: 0);
    }

    public function test_penalty_months_counts_the_charges_in_a_pre_switch_periods_figure(): void
    {
        LatePenaltySetting::query()->create(['monthly_from' => '2026-10-01']);

        // Period 1 is eight months late on 20 October, with its one charge.
        $this->nightlyOn('2026-10-20');
        $this->assertPenalty(1, charged: 200, months: 1);
        $this->assertSame(1, $this->getJson("/api/loans/{$this->loan->id}/summary")->json('data.penalty_months'));

        // 1 November, a full month after the switch: its second charge.
        $this->nightlyOn('2026-11-01');
        $this->assertPenalty(1, charged: 400, months: 2);
        $this->assertSame(2, $this->getJson("/api/loans/{$this->loan->id}/summary")->json('data.penalty_months'));
        $this->assertSame(2, $this->getJson("/api/loans/{$this->loan->id}/amortization-schedule")->json('data.0.penalty_months'));
        $this->assertSame([
            ['2026-02-19', 'Late penalty, period 1', 200.0],
            ['2026-11-01', 'Late penalty, period 1, month 2', 200.0],
        ], $this->periodPenaltyLines(1));
    }

    public function test_a_balance_reduction_keeps_the_months_charged_and_charges_the_next_on_the_new_principal(): void
    {
        // 15 August: period 1 is six months late, 6 × ₱200.00.
        $this->nightlyOn('2026-08-15');
        $this->assertPenalty(1, charged: 1200, months: 6);

        // ₱60,000.00 unpaid to ₱30,000.00: each of the six periods loses ₱5,000.00.
        $this->adjustBalanceOn('2026-08-20', 30000);
        $this->assertEqualsWithDelta(5000, (float) $this->period(1)->principal_due, 0.001);

        $this->nightlyOn('2026-08-21');
        $this->assertPenalty(1, charged: 1200, months: 6);

        // 15 September: 2% of the ₱5,000.00 now unpaid.
        $this->nightlyOn('2026-09-15');
        $this->assertPenalty(1, charged: 1300, months: 7);
        $this->assertLedgerClosesOnTheSummary();
    }

    public function test_a_balance_increase_keeps_the_months_charged_and_charges_the_next_on_the_new_principal(): void
    {
        $this->nightlyOn('2026-08-15');

        // ₱60,000.00 to ₱90,000.00: each period gains ₱5,000.00.
        $this->adjustBalanceOn('2026-08-20', 90000);
        $this->assertEqualsWithDelta(15000, (float) $this->period(1)->principal_due, 0.001);

        $this->nightlyOn('2026-08-21');
        $this->assertPenalty(1, charged: 1200, months: 6);

        // 15 September: 2% of ₱15,000.00.
        $this->nightlyOn('2026-09-15');
        $this->assertPenalty(1, charged: 1500, months: 7);
        $this->assertLedgerClosesOnTheSummary();
    }

    public function test_a_void_charges_the_months_up_to_today_not_up_to_the_payment_date(): void
    {
        // Released 15 March: period 1 falls due 15 April, late from 19 April.
        $this->travelTo(Carbon::parse('2026-03-15 09:00'));
        $this->loan = $this->createReleasedLoan(['start_date' => '2026-03-15']);

        // 15 June, two months late: ₱400.00 penalty, ₱1,800.00 interest, ₱10,000.00 principal.
        $repayment = $this->payOn('2026-06-15', 12200);
        $this->assertSame('paid', $this->period(1)->status);

        $this->nightlyOn('2026-08-20');
        $this->travelTo(Carbon::parse('2026-08-20 10:00'));
        app(RepaymentService::class)->voidRepayment($repayment, 'Keyed twice', $this->admin);

        // 20 August: four months late, 4 × ₱200.00.
        $this->assertPenalty(1, charged: 800, paid: 0, months: 4);
        $this->assertOwedAgreesEverywhere();
    }

    public function test_a_partial_void_of_a_principal_payment_puts_the_later_months_back_up(): void
    {
        // 20 February: ₱200.00 penalty, ₱1,800.00 interest, ₱3,000.00 principal.
        $this->payOn('2026-02-20', 5000);
        // 1 March: ₱2,000.00 more principal, leaving ₱5,000.00 unpaid.
        $second = $this->payOn('2026-03-01', 2000);

        // ₱200.00 on 19 February, then 2% of ₱5,000.00 on 15 April and 15 May.
        $this->nightlyOn('2026-05-15');
        $this->assertPenalty(1, charged: 400, paid: 200, months: 3);

        // The night's run on 20 May charges period 4, late from 19 May.
        $this->nightlyOn('2026-05-20');
        $this->travelTo(Carbon::parse('2026-05-20 10:00'));
        app(RepaymentService::class)->voidRepayment($second, 'Keyed twice', $this->admin);

        // Without the ₱2,000.00, ₱7,000.00 was unpaid on 15 April and 15 May: ₱140.00 each.
        $this->assertSame('partial', $this->period(1)->status);
        $this->assertPenalty(1, charged: 480, paid: 200, months: 3);
        $this->assertOwedAgreesEverywhere();
    }

    public function test_a_backdated_payment_leaves_the_penalty_charged_up_to_today(): void
    {
        $this->nightlyOn('2026-05-20');

        // Recorded on 20 May, dated 1 March: it pays period 1's one charge as
        // it stood then, ₱200.00, and ₱100.00 of its interest.
        $this->travelTo(Carbon::parse('2026-05-20 10:00'));
        $this->postJson("/api/loans/{$this->loan->id}/repayments", [
            'amount_paid' => 300,
            'payment_date' => '2026-03-01',
        ])->assertCreated();

        // On 20 May period 1 is three months late.
        $this->assertPenalty(1, charged: 600, paid: 200, months: 3);
        $this->assertPenalty(2, charged: 400, months: 2);
        $this->assertOwedAgreesEverywhere();
    }

    public function test_a_period_a_pre_switch_waiver_made_penalty_free_stays_free_after_a_void_and_a_new_waiver(): void
    {
        $this->nightlyOn('2026-04-15');
        $payment = $this->payOn('2026-04-16', 100);
        $this->waiveOn('2026-04-20', [$this->period(1)->id]);
        $this->forgetWaiverCheckpoint(1);

        // After the switch the payment is voided: the ₱100.00 it paid is owed again.
        $this->travelTo(Carbon::parse('2026-04-25 10:00'));
        app(RepaymentService::class)->voidRepayment($payment, 'Keyed twice', $this->admin);
        $this->assertPenalty(1, charged: 100, paid: 0);

        $this->waiveOn('2026-04-28', [$this->period(1)->id]);
        $this->assertNull($this->period(1)->penalty_checkpoint_on);

        $this->nightlyOn('2026-07-20');
        $this->assertPenalty(1, charged: 0);
    }

    public function test_the_ledger_keeps_one_line_for_a_period_late_before_the_switch_with_no_charge_since(): void
    {
        LatePenaltySetting::query()->create(['monthly_from' => '2026-04-10']);

        // As the once-only rule stamped it before the switch, before the
        // principal it was worked on came down.
        $this->period(1)->update(['penalty_amount' => 250, 'penalty_months' => 0, 'status' => 'overdue']);
        $this->travelTo(Carbon::parse('2026-05-20 10:00'));

        $this->assertSame([['2026-02-19', 'Late penalty, period 1', 250.0]], $this->periodPenaltyLines(1));
    }

    public function test_the_ledger_keeps_one_line_for_a_period_waived_for_good(): void
    {
        $this->nightlyOn('2026-04-15');
        $this->waiveOn('2026-04-20', [$this->period(1)->id]);
        $this->forgetWaiverCheckpoint(1);
        $this->nightlyOn('2026-06-20');

        $this->assertSame([['2026-02-19', 'Late penalty, period 1', 400.0]], $this->periodPenaltyLines(1));

        $rows = $this->getJson("/api/loans/{$this->loan->id}/ledger")->assertOk()->json('data.rows');
        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual(0, (float) $row['balances']['penalty'], $row['description']);
        }
        $this->assertLedgerClosesOnTheSummary();
    }

    public function test_due_dates_on_the_31st_and_at_the_end_of_february_count_full_months_to_the_capped_day(): void
    {
        $this->travelTo(Carbon::parse('2026-01-31 09:00'));
        $this->loan = $this->createReleasedLoan(['start_date' => '2026-01-31']);
        $this->assertSame(['2026-02-28', '2026-03-31', '2026-04-30'], array_slice(
            $this->loan->amortizationSchedules->sortBy('period_number')->map(fn (AmortizationSchedule $s): string => $s->due_date->toDateString())->values()->all(),
            0,
            3,
        ));

        // Period 1, due 28 February: a full month is 28 March, two are 28 April.
        $this->nightlyOn('2026-04-27');
        $this->assertPenalty(1, charged: 200, months: 1);
        $this->nightlyOn('2026-04-28');
        $this->assertPenalty(1, charged: 400, months: 2);

        // Period 2, due 31 March: one month is 30 April, two are 31 May.
        $this->nightlyOn('2026-05-30');
        $this->assertPenalty(2, charged: 200, months: 1);
        $this->nightlyOn('2026-05-31');
        $this->assertPenalty(2, charged: 400, months: 2);
    }

    public function test_a_due_date_on_29_february_counts_full_months_from_the_29th(): void
    {
        $this->travelTo(Carbon::parse('2028-01-29 09:00'));
        $this->loan = $this->createReleasedLoan(['start_date' => '2028-01-29']);
        $this->assertSame('2028-02-29', $this->period(1)->due_date->toDateString());

        $this->nightlyOn('2028-04-28');
        $this->assertPenalty(1, charged: 200, months: 1);
        $this->nightlyOn('2028-04-29');
        $this->assertPenalty(1, charged: 400, months: 2);
    }

    public function test_a_payment_dated_before_a_balance_adjustment_is_charged_as_of_its_own_date(): void
    {
        // 20 May: period 1 has its charges of 19 Feb, 15 Apr and 15 May,
        // 3 × ₱200.00 = ₱600.00. The adjustment that day takes ₱6,000.00 off
        // the ₱60,000.00 unpaid, ₱1,000.00 a period: period 1 owes ₱9,000.00.
        $this->nightlyOn('2026-05-20');
        $this->adjustBalanceOn('2026-05-20', 54000);
        $this->assertEqualsWithDelta(9000, (float) $this->period(1)->principal_due, 0.001);

        // Recorded on 25 May, dated 20 April. On 20 April period 1 had two
        // charges, 2% of ₱10,000.00 each: ₱400.00. The payment takes that,
        // ₱1,800.00 interest and ₱5,000.00 principal.
        $this->travelTo(Carbon::parse('2026-05-25 10:00'));
        $id = $this->postJson("/api/loans/{$this->loan->id}/repayments", [
            'amount_paid' => 7200,
            'payment_date' => '2026-04-20',
        ])->assertCreated()->json('data.id');
        $this->assertEqualsWithDelta(400, (float) Repayment::findOrFail($id)->penalty_applied, 0.001);

        // As of 25 May: ₱200.00 (19 Feb) + ₱200.00 (15 Apr), then 15 May on
        // the ₱10,000.00 owed that day less the ₱5,000.00 paid on 20 April:
        // ₱100.00. ₱500.00 charged, ₱400.00 paid.
        $this->assertPenalty(1, charged: 500, paid: 400, months: 3);
        $this->assertOwedAgreesEverywhere();

        // 15 June: on the ₱9,000.00 owed since the adjustment, less ₱5,000.00
        // paid: 2% of ₱4,000.00 = ₱80.00, so ₱580.00.
        $this->nightlyOn('2026-06-15');
        $this->assertPenalty(1, charged: 580, paid: 400, months: 4);
    }

    public function test_a_void_after_a_balance_adjustment_charges_each_month_on_the_principal_then_owed(): void
    {
        // 20 February: ₱200.00 penalty, ₱1,800.00 interest, ₱5,000.00 principal.
        $payment = $this->payOn('2026-02-20', 7000);

        // ₱200.00 (19 Feb, on ₱10,000.00) + ₱100.00 (15 Apr, on ₱5,000.00).
        $this->nightlyOn('2026-04-15');
        $this->assertPenalty(1, charged: 300, paid: 200, months: 2);

        // 20 April: ₱55,000.00 unpaid to ₱49,500.00. Period 1 (₱5,000.00 of
        // it) takes ₱500.00 off and owes ₱9,500.00; the others ₱1,000.00 each.
        $this->adjustBalanceOn('2026-04-20', 49500);
        $this->assertEqualsWithDelta(9500, (float) $this->period(1)->principal_due, 0.001);

        // 15 May: 2% of ₱9,500.00 less ₱5,000.00 paid = ₱90.00, so ₱390.00.
        $this->nightlyOn('2026-05-15');
        $this->assertPenalty(1, charged: 390, paid: 200, months: 3);

        $this->nightlyOn('2026-05-20');
        $this->travelTo(Carbon::parse('2026-05-20 10:00'));
        app(RepaymentService::class)->voidRepayment($payment, 'Keyed twice', $this->admin);

        // As if the payment never was: ₱200.00 (19 Feb) + ₱200.00 (15 Apr, on
        // ₱10,000.00, before the adjustment) + ₱190.00 (15 May, on ₱9,500.00).
        $this->assertPenalty(1, charged: 590, paid: 0, months: 3);
        $this->assertOwedAgreesEverywhere();
    }

    public function test_two_balance_adjustments_on_a_period_each_charge_their_own_months(): void
    {
        // 15 August: 6 × ₱200.00 on ₱10,000.00.
        $this->nightlyOn('2026-08-15');
        $this->adjustBalanceOn('2026-08-20', 30000);
        // 15 September on ₱5,000.00: ₱100.00.
        $this->nightlyOn('2026-09-15');
        $this->assertPenalty(1, charged: 1300, months: 7);

        // ₱30,000.00 back up to ₱60,000.00: period 1 owes ₱10,000.00 again.
        $this->adjustBalanceOn('2026-09-20', 60000);
        // 15 October on ₱10,000.00: ₱200.00.
        $this->nightlyOn('2026-10-15');
        $this->assertPenalty(1, charged: 1500, months: 8);

        $this->assertSame(
            [['2026-08-20', 10000.0, 5000.0], ['2026-09-20', 5000.0, 10000.0]],
            AmortizationPrincipalChange::where('amortization_schedule_id', $this->period(1)->id)
                ->orderBy('id')
                ->get()
                ->map(fn (AmortizationPrincipalChange $c): array => [$c->effective_on->toDateString(), (float) $c->principal_due_before, (float) $c->principal_due_after])
                ->all(),
        );

        // Each month's line is the charge as it fell.
        $this->assertSame([
            ['2026-02-19', 'Late penalty, period 1', 200.0],
            ['2026-04-15', 'Late penalty, period 1, month 2', 200.0],
            ['2026-05-15', 'Late penalty, period 1, month 3', 200.0],
            ['2026-06-15', 'Late penalty, period 1, month 4', 200.0],
            ['2026-07-15', 'Late penalty, period 1, month 5', 200.0],
            ['2026-08-15', 'Late penalty, period 1, month 6', 200.0],
            ['2026-09-15', 'Late penalty, period 1, month 7', 100.0],
            ['2026-10-15', 'Late penalty, period 1, month 8', 200.0],
        ], $this->periodPenaltyLines(1));
        $this->assertLedgerClosesOnTheSummary();
    }

    public function test_an_adjustment_then_a_waiver_then_a_void_of_an_earlier_payment(): void
    {
        // 20 February: ₱200.00 penalty, ₱1,800.00 interest, ₱5,000.00 principal.
        $payment = $this->payOn('2026-02-20', 7000);
        $this->nightlyOn('2026-04-15');

        // Period 1 to ₱9,500.00, as in the void test above.
        $this->adjustBalanceOn('2026-04-20', 49500);

        // 25 April: ₱300.00 charged, ₱200.00 paid: the waiver forgives ₱100.00
        // and keeps ₱200.00.
        $this->waiveOn('2026-04-25', [$this->period(1)->id]);
        $this->assertPenalty(1, charged: 200, paid: 200, months: 2);

        // 15 May: ₱200.00 + 2% of (₱9,500.00 − ₱5,000.00) = ₱290.00.
        $this->nightlyOn('2026-05-15');
        $this->assertPenalty(1, charged: 290, paid: 200, months: 3);

        $this->nightlyOn('2026-05-20');
        $this->travelTo(Carbon::parse('2026-05-20 10:00'));
        app(RepaymentService::class)->voidRepayment($payment, 'Keyed twice', $this->admin);

        // The ₱200.00 the waiver kept is owed again, and 15 May is on the
        // whole ₱9,500.00: ₱190.00. ₱390.00.
        $this->assertPenalty(1, charged: 390, paid: 0, months: 3);
        $this->assertPenaltyBalanceNeverNegative();
        $this->assertOwedAgreesEverywhere();
    }

    public function test_the_ledger_balance_stays_at_or_above_zero_with_an_adjustment_between_two_waivers(): void
    {
        // 15 April: ₱400.00, waived on 20 April.
        $this->nightlyOn('2026-04-15');
        $this->waiveOn('2026-04-20', [$this->period(1)->id]);

        // 1 May: ₱60,000.00 to ₱12,000.00, every period to ₱2,000.00.
        $this->adjustBalanceOn('2026-05-01', 12000);

        // 15 May: 2% of ₱2,000.00 = ₱40.00, waived on 20 May.
        $this->nightlyOn('2026-05-15');
        $this->waiveOn('2026-05-20', [$this->period(1)->id]);
        $this->nightlyOn('2026-06-15');
        $this->assertPenalty(1, charged: 40, months: 4);

        $this->assertSame([
            ['2026-02-19', 'Late penalty, period 1', 200.0],
            ['2026-04-15', 'Late penalty, period 1, month 2', 200.0],
            ['2026-05-15', 'Late penalty, period 1, month 3', 40.0],
            ['2026-06-15', 'Late penalty, period 1, month 4', 40.0],
        ], $this->periodPenaltyLines(1));
        $this->assertPenaltyBalanceNeverNegative();
        $this->assertLedgerClosesOnTheSummary();
    }

    public function test_the_floor_at_penalty_paid_is_on_the_whole_charge_after_an_adjustment(): void
    {
        $this->nightlyOn('2026-03-20');
        $this->period(1)->update(['penalty_amount' => 500, 'penalty_paid' => 500]);

        // ₱60,000.00 to ₱30,000.00 on 20 April: period 1 owes ₱5,000.00.
        $this->nightlyOn('2026-04-15');
        $this->adjustBalanceOn('2026-04-20', 30000);

        // ₱200.00 + ₱200.00 + 2% of ₱5,000.00 = ₱500.00, not above the ₱500.00 paid.
        $this->nightlyOn('2026-05-15');
        $this->assertPenalty(1, charged: 500, paid: 500, months: 3);

        // + ₱100.00 on 15 June: ₱600.00.
        $this->nightlyOn('2026-06-15');
        $this->assertPenalty(1, charged: 600, paid: 500, months: 4);
    }

    public function test_a_waiver_on_a_period_not_late_writes_the_charges_it_really_has(): void
    {
        $this->travelTo(Carbon::parse('2026-03-01 10:00'));
        $this->period(6)->update(['penalty_amount' => 100, 'penalty_months' => 3]);

        $this->waiveOn('2026-03-01', [$this->period(6)->id]);

        $period = $this->period(6);
        $this->assertSame(0, $period->penalty_months);
        $this->assertSame(0, $period->penalty_checkpoint_months);
        $this->assertEqualsWithDelta(0, (float) $period->penalty_amount, 0.001);
    }

    public function test_the_payment_preview_after_a_balance_adjustment_equals_the_payment(): void
    {
        $this->nightlyOn('2026-08-15');
        $this->adjustBalanceOn('2026-08-20', 30000);

        // No run since 15 August: the payment charges 15 September itself.
        $this->travelTo(Carbon::parse('2026-09-20 10:00'));
        $preview = $this->postJson("/api/loans/{$this->loan->id}/repayments/preview", [
            'amount_paid' => 50000,
            'payment_date' => '2026-09-20',
        ])->assertOk()->json('data');
        $repayment = $this->payOn('2026-09-20', 50000);

        $this->assertEqualsWithDelta((float) $repayment->penalty_applied, (float) $preview['total_penalty'], 0.001);
        // Period 1: 6 × ₱200.00 + 2% of ₱5,000.00 = ₱1,300.00, all paid.
        $this->assertPenalty(1, charged: 1300, paid: 1300, months: 7);
    }

    public function test_the_extension_preview_after_a_balance_adjustment_equals_the_extension(): void
    {
        // ₱60,000.00 for one month, due 27 May 2026, late from 31 May.
        $this->travelTo(Carbon::parse('2026-04-27 09:00'));
        $this->loan = $this->createReleasedLoan([
            'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly', 'interest_rate' => 3.0],
            'start_date' => '2026-04-27',
        ]);

        // 10 June: ₱60,000.00 to ₱30,000.00.
        $this->adjustBalanceOn('2026-06-10', 30000);

        // 30 July: ₱1,200.00 (31 May, on ₱60,000.00) + ₱600.00 (27 July, on ₱30,000.00).
        $this->travelTo(Carbon::parse('2026-07-30 10:00'));
        $preview = $this->getJson("/api/loans/{$this->loan->id}/extension-preview?interest_option=pay")->assertOk()->json('data');
        $this->postJson("/api/loans/{$this->loan->id}/extend", ['interest_option' => 'pay'])->assertOk();
        $values = LoanAdjustment::where('loan_id', $this->loan->id)->where('adjustment_type', 'extension')->sole()->new_values;

        $this->assertEqualsWithDelta(1800, (float) $preview['collect_now']['penalty_part'], 0.001);
        $this->assertEqualsWithDelta(1800, (float) $values['penalty_paid'], 0.001);
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
        // Left out of the count, as the nightly run leaves them: a loan with no
        // penalty rate and one no longer collectible.
        $this->createReleasedLoan(['start_date' => '2026-01-15', 'product' => ['penalty_rate' => 0]]);
        $this->createReleasedLoan(['start_date' => '2026-01-15'])->update(['status' => 'completed']);

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

    /**
     * Each penalty line of the loan's Ledger: date, description, amount and
     * the penalty balance after it.
     *
     * @return list<array{0: string, 1: string, 2: float, 3: float}>
     */
    private function penaltyLines(): array
    {
        return collect($this->getJson("/api/loans/{$this->loan->id}/ledger")->assertOk()->json('data.rows'))
            ->where('kind', 'penalty_charge')
            ->map(fn (array $row): array => [$row['date'], $row['description'], (float) $row['penalty'], (float) $row['balances']['penalty']])
            ->values()
            ->all();
    }

    /**
     * The Ledger's penalty lines for one period: date, description and amount.
     *
     * @return list<array{0: string, 1: string, 2: float}>
     */
    private function periodPenaltyLines(int $period): array
    {
        $prefix = "Late penalty, period {$period}";

        return collect($this->getJson("/api/loans/{$this->loan->id}/ledger")->assertOk()->json('data.rows'))
            ->where('kind', 'penalty_charge')
            ->filter(fn (array $row): bool => $row['description'] === $prefix || str_starts_with($row['description'], "{$prefix},"))
            ->map(fn (array $row): array => [$row['date'], $row['description'], (float) $row['penalty']])
            ->values()
            ->all();
    }

    /**
     * The penalty stored, the summary, the Ledger and the loan row agree: the
     * stored figures are those a payment today would charge.
     */
    private function assertOwedAgreesEverywhere(): void
    {
        $summary = $this->getJson("/api/loans/{$this->loan->id}/summary")->assertOk()->json('data');
        $row = $this->getJson("/api/loans/{$this->loan->id}")->assertOk()->json('data');

        $this->assertEqualsWithDelta((float) $summary['total_outstanding'], (float) $summary['payoff_amount'], 0.001, 'payoff equals what is stored as owed');
        $this->assertEqualsWithDelta((float) $summary['outstanding_penalty'], (float) $row['amount_due_now']['penalty'], 0.001, 'amount due now carries the stored penalty');
        $this->assertLedgerClosesOnTheSummary();
    }

    /** No row of the loan's Ledger leaves the running penalty balance below ₱0.00. */
    private function assertPenaltyBalanceNeverNegative(): void
    {
        foreach ($this->getJson("/api/loans/{$this->loan->id}/ledger")->assertOk()->json('data.rows') as $row) {
            $this->assertGreaterThanOrEqual(0, (float) $row['balances']['penalty'], "{$row['date']} {$row['description']}");
        }
    }

    /** A waiver applied before the switch: its link, with no checkpoint. */
    private function forgetWaiverCheckpoint(int $period): void
    {
        $this->period($period)->update(['penalty_checkpoint_on' => null, 'penalty_checkpoint_amount' => null, 'penalty_checkpoint_months' => null]);
    }

    private function adjustBalanceOn(string $date, float $targetPrincipalBalance): void
    {
        $this->travelTo(Carbon::parse("{$date} 10:00"));

        $id = $this->postJson("/api/loans/{$this->loan->id}/adjustments", [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['target_principal_balance' => $targetPrincipalBalance, 'reason' => 'Agreed settlement'],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk()->assertJsonPath('data.status', 'applied');
    }

    /** The Ledger closes on the summary, and the statement's debits less credits are what is owed. */
    private function assertLedgerClosesOnTheSummary(): void
    {
        $ledger = $this->getJson("/api/loans/{$this->loan->id}/ledger")->assertOk()->json('data');
        $summary = $this->getJson("/api/loans/{$this->loan->id}/summary")->assertOk()->json('data');
        $statement = $this->getJson("/api/reports/statement-of-account/{$this->loan->id}")->assertOk()->json('data');

        $last = end($ledger['rows']);
        $this->assertEqualsWithDelta((float) $summary['outstanding_penalty'], (float) $last['balances']['penalty'], 0.001);
        $this->assertEqualsWithDelta((float) $summary['outstanding_penalty'], (float) $ledger['closing']['penalty'], 0.001);
        $this->assertEqualsWithDelta(
            (float) $statement['summary']['outstanding_balance'],
            round((float) $statement['transaction_totals']['debit'] - (float) $statement['transaction_totals']['credit'], 2),
            0.001,
        );
    }

    /** @param list<int> $scheduleIds */
    private function waiveOn(string $date, array $scheduleIds, string $time = '10:00'): LoanAdjustment
    {
        $this->travelTo(Carbon::parse("{$date} {$time}"));

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
