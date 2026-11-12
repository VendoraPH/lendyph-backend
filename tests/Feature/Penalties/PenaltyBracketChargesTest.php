<?php

namespace Tests\Feature\Penalties;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use App\Services\RepaymentService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Charging a loan created under a penalty configuration (Phase 4, default
 * A): the charge days and the base are the monthly rule's (the first day
 * past grace, then each full month after the due date, on the principal
 * unpaid that day); only the rate changes, to the bracket covering that
 * day's days past the due date.
 *
 * The loan: ₱60,000 over six months at 3% straight, ₱10,000 principal a
 * period, started 2026-01-15, so period 1 falls due 2026-02-15 (day 1 is
 * 16 February) and period 2 on 2026-03-15. The brackets are set on the loan
 * directly, as its creation copies them (PenaltyConfigSnapshotTest covers the
 * copy).
 */
class PenaltyBracketChargesTest extends TestCase
{
    use SetupLendyPH;

    private const KEVIN = [
        ['days_from' => 1, 'days_to' => 5, 'rate' => 20],
        ['days_from' => 6, 'days_to' => 10, 'rate' => 30],
        ['days_from' => 11, 'days_to' => null, 'rate' => 50],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
    }

    /** @return array<string, array{int, float}> */
    public static function bracketEdges(): array
    {
        return [
            'day 1' => [1, 2000],
            'day 5' => [5, 2000],
            'day 6' => [6, 3000],
            'day 10' => [10, 3000],
            'day 11' => [11, 5000],
            'day 40' => [40, 5000],
        ];
    }

    #[DataProvider('bracketEdges')]
    public function test_a_charge_takes_the_rate_of_the_bracket_covering_its_day(int $day, float $charged): void
    {
        // The first charge falls on the first day past grace: grace day - 1
        // puts it on `$day`.
        $loan = $this->bracketLoan(self::KEVIN, grace: $day - 1);

        $this->nightlyOn(Carbon::parse('2026-02-15')->addDays($day - 1)->toDateString());
        $this->assertPenalty($loan, 1, charged: 0, months: 0);

        $this->nightlyOn(Carbon::parse('2026-02-15')->addDays($day)->toDateString());
        $this->assertPenalty($loan, 1, charged: $charged, months: 1);
    }

    public function test_with_no_grace_each_monthly_charge_takes_the_bracket_of_its_own_day(): void
    {
        $loan = $this->bracketLoan(self::KEVIN, grace: 0);

        // Day 1: 20% of ₱10,000.
        $this->nightlyOn('2026-02-16');
        $this->assertPenalty($loan, 1, charged: 2000, months: 1);

        // 15 March is a full month (day 28) but still one charge; 15 April is
        // the second, on day 59: 50%. Period 2 is on its day 31: one charge,
        // at day 1's 20%.
        $this->nightlyOn('2026-04-15');
        $this->assertPenalty($loan, 1, charged: 7000, months: 2);
        $this->assertPenalty($loan, 2, charged: 2000, months: 1);
    }

    public function test_a_day_no_bracket_covers_charges_nothing(): void
    {
        $brackets = [['days_from' => 1, 'days_to' => 5, 'rate' => 20]];

        // Grace 6: the first charge falls on day 7, which no bracket covers.
        $late = $this->bracketLoan($brackets, grace: 6);
        // Grace 0: day 1 is charged; the second month (day 59) is not.
        $early = $this->bracketLoan($brackets, grace: 0);

        $this->nightlyOn('2026-04-15');

        $this->assertPenalty($late, 1, charged: 0, months: 0);
        $this->assertSame('overdue', $this->period($late, 1)->status);
        $this->assertPenalty($early, 1, charged: 2000, months: 1);
        $this->assertSame([], $this->penaltyLines($late));
    }

    public function test_one_open_bracket_at_the_products_rate_charges_what_the_rate_does_to_the_centavo(): void
    {
        $rate = $this->createReleasedLoan(['start_date' => '2026-01-15']);
        $bracket = $this->bracketLoan([['days_from' => 1, 'days_to' => null, 'rate' => 2]], grace: 3);

        // The nightly job.
        $this->nightlyOn('2026-04-15');
        $this->assertSamePenalties($rate, $bracket);

        // A part payment and its preview: penalty first, then interest, then
        // part of period 1's principal, which lowers the months after it.
        $this->travelTo(Carbon::parse('2026-04-20 09:00'));
        $previews = array_map(fn (Loan $loan): array => $this->postJson("/api/loans/{$loan->id}/repayments/preview", [
            'amount_paid' => 7350.55,
            'payment_date' => '2026-04-20',
        ])->assertOk()->json('data'), [$rate, $bracket]);
        $this->assertSame($previews[0]['total_penalty'], $previews[1]['total_penalty']);

        $payments = array_map(fn (Loan $loan): Repayment => $this->payOn($loan, '2026-04-20', 7350.55), [$rate, $bracket]);
        $this->assertSame($payments[0]->penalty_applied, $payments[1]->penalty_applied);
        $this->assertEqualsWithDelta((float) $previews[0]['total_penalty'], (float) $payments[0]->penalty_applied, 0.001);

        $this->nightlyOn('2026-06-15');
        $this->assertSamePenalties($rate, $bracket);

        // Presets, payoff and what is owed.
        $this->assertSameSummaries($rate, $bracket);

        // The Ledger: the same lines on the same days for the same amounts;
        // only the wording adds the bracket's rate.
        $rateLines = $this->penaltyLines($rate);
        $bracketLines = $this->penaltyLines($bracket);
        $this->assertNotEmpty($rateLines);
        $this->assertSame(array_column($rateLines, 0), array_column($bracketLines, 0));
        $this->assertSame(array_column($rateLines, 2), array_column($bracketLines, 2));
        $this->assertSame(
            array_map(fn (string $description): string => "{$description}, at 2%", array_column($rateLines, 1)),
            array_column($bracketLines, 1),
        );

        // A void reopens the periods with the same charges.
        $this->travelTo(Carbon::parse('2026-06-16 09:00'));
        foreach ($payments as $payment) {
            app(RepaymentService::class)->voidRepayment($payment->fresh(), 'Keyed twice', $this->admin);
        }
        $this->assertSamePenalties($rate, $bracket);
        $this->assertSameSummaries($rate, $bracket);
    }

    public function test_one_open_bracket_matches_the_rate_on_auto_pay(): void
    {
        $rate = $this->createReleasedLoan(['start_date' => '2026-01-15']);
        $bracket = $this->bracketLoan([['days_from' => 1, 'days_to' => null, 'rate' => 2]], grace: 3);

        foreach ([$rate, $bracket] as $index => $loan) {
            $loan->update([
                'auto_pay' => true,
                'cbs_reference' => 'CBS-2026-0000'.($index + 1),
                'auto_pay_enabled_at' => now(),
                'auto_pay_enabled_by' => $this->admin->id,
            ]);
        }

        $this->travelTo(Carbon::parse('2026-05-15 10:00'));
        $range = ['date_from' => '2026-02-15', 'date_to' => '2026-04-15'];

        $summary = $this->getJson('/api/auto-pay/preview?'.http_build_query($range))->assertOk()->json('data.summary');
        $this->postJson('/api/auto-pay/process', $range)->assertCreated();

        $ratePosted = Repayment::where('loan_id', $rate->id)->sole();
        $bracketPosted = Repayment::where('loan_id', $bracket->id)->sole();

        // 3 months on period 1, 2 on period 2 and 1 on period 3, ₱200 each.
        $this->assertEqualsWithDelta(1200, (float) $ratePosted->penalty_applied, 0.001);
        $this->assertSame($ratePosted->penalty_applied, $bracketPosted->penalty_applied);
        $this->assertEqualsWithDelta(2400, (float) $summary['total_penalty'], 0.001);
    }

    public function test_one_open_bracket_matches_the_rate_on_an_extension_that_pays(): void
    {
        $this->travelTo(Carbon::parse('2026-04-27 09:00'));
        $product = ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly', 'interest_rate' => 3.0];
        $rate = $this->createReleasedLoan(['product' => $product, 'start_date' => '2026-04-27']);
        $bracket = $this->bracketLoan([['days_from' => 1, 'days_to' => null, 'rate' => 2]], grace: 3, product: $product, start: '2026-04-27');

        $this->travelTo(Carbon::parse('2026-07-28 10:00'));

        $collected = [];
        foreach ([$rate, $bracket] as $loan) {
            $preview = $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=pay")->assertOk()->json('data');
            $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();
            $values = LoanAdjustment::where('loan_id', $loan->id)->where('adjustment_type', 'extension')->sole()->new_values;

            $this->assertEqualsWithDelta((float) $preview['collect_now']['penalty_part'], (float) $values['penalty_paid'], 0.001);
            $collected[] = $values['penalty_paid'];
        }

        // Two charges of 2% of ₱60,000.
        $this->assertEqualsWithDelta(2400, (float) $collected[0], 0.001);
        $this->assertSame($collected[0], $collected[1]);
    }

    public function test_a_charge_after_a_waiver_takes_the_bracket_of_its_own_day(): void
    {
        $loan = $this->bracketLoan([
            ['days_from' => 1, 'days_to' => 30, 'rate' => 20],
            ['days_from' => 31, 'days_to' => null, 'rate' => 50],
        ], grace: 0);

        $this->nightlyOn('2026-02-16');
        $this->assertPenalty($loan, 1, charged: 2000, months: 1);

        $this->waiveOn($loan, '2026-02-20', [$this->period($loan, 1)->id]);
        $this->assertPenalty($loan, 1, charged: 0, months: 1);

        // The next charge falls on the due date's monthly anniversary after
        // the waiver, 15 April, day 59: 50%, not the first charge's 20%.
        $this->nightlyOn('2026-04-14');
        $this->assertPenalty($loan, 1, charged: 0, months: 1);

        $this->nightlyOn('2026-04-15');
        $this->assertPenalty($loan, 1, charged: 5000, months: 2);

        $this->assertSame(
            [['2026-02-16', 'Late penalty, period 1, at 20%', 2000.0], ['2026-04-15', 'Late penalty, period 1, month 2, at 50%', 5000.0]],
            array_map(fn (array $line): array => [$line[0], $line[1], $line[2]], array_values(array_filter(
                $this->penaltyLines($loan),
                fn (array $line): bool => str_starts_with($line[1], 'Late penalty, period 1,'),
            ))),
        );
    }

    public function test_the_ledger_line_names_the_brackets_rate_never_rounded(): void
    {
        $loan = $this->bracketLoan([
            ['days_from' => 1, 'days_to' => 30, 'rate' => 2.5],
            ['days_from' => 31, 'days_to' => null, 'rate' => 3.1234],
        ], grace: 0);

        $this->nightlyOn('2026-04-15');

        // ₱250.00 on day 1, then 3.1234% of ₱10,000 = ₱312.34 on day 59;
        // period 2's day 1 is ₱250.00.
        $this->assertSame([
            ['2026-02-16', 'Late penalty, period 1, at 2.5%', 250.0],
            ['2026-03-16', 'Late penalty, period 2, at 2.5%', 250.0],
            ['2026-04-15', 'Late penalty, period 1, month 2, at 3.1234%', 312.34],
        ], array_map(fn (array $line): array => [$line[0], $line[1], $line[2]], $this->penaltyLines($loan)));
    }

    public function test_the_loan_row_sends_its_brackets_and_configuration(): void
    {
        $loan = $this->bracketLoan(self::KEVIN, grace: 0);
        $loan->update(['penalty_config_name' => 'Standard Loan Penalty']);

        $row = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');

        $this->assertSame('Standard Loan Penalty', $row['penalty_config_name']);
        $this->assertNull($row['penalty_config_id']);
        $this->assertEquals(self::KEVIN, $row['penalty_brackets']);

        $rate = $this->createReleasedLoan(['start_date' => '2026-01-15']);
        $plain = $this->getJson("/api/loans/{$rate->id}")->assertOk()->json('data');
        $this->assertNull($plain['penalty_brackets']);
        $this->assertNull($plain['penalty_config_name']);
        $this->assertEqualsWithDelta(2.0, (float) $plain['penalty_rate'], 0.0001);
    }

    /**
     * A released loan whose penalty comes from `$brackets`, as one created
     * under a configuration stores it: the brackets, and a penalty rate of 0.
     *
     * @param  list<array{days_from: int, days_to: int|null, rate: int|float}>  $brackets
     * @param  array<string, mixed>  $product
     */
    private function bracketLoan(array $brackets, int $grace, array $product = [], string $start = '2026-01-15'): Loan
    {
        $loan = $this->createReleasedLoan(['product' => $product, 'start_date' => $start]);
        $loan->update(['penalty_rate' => 0, 'penalty_brackets' => $brackets, 'grace_period_days' => $grace]);

        return $loan->fresh();
    }

    private function nightlyOn(string $date): void
    {
        $this->travelTo(Carbon::parse("{$date} 00:10"));
        $this->artisan('loans:apply-penalties')->assertSuccessful();
    }

    private function payOn(Loan $loan, string $date, float $amount): Repayment
    {
        $this->travelTo(Carbon::parse("{$date} 09:00"));

        $id = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'amount_paid' => $amount,
            'payment_date' => $date,
        ])->assertCreated()->json('data.id');

        return Repayment::findOrFail($id);
    }

    /** @param list<int> $scheduleIds */
    private function waiveOn(Loan $loan, string $date, array $scheduleIds): void
    {
        $this->travelTo(Carbon::parse("{$date} 10:00"));

        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'penalty_waiver',
            'new_values' => ['schedule_ids' => $scheduleIds],
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk()->assertJsonPath('data.status', 'applied');
    }

    /**
     * The loan's Ledger penalty lines: date, description, amount.
     *
     * @return list<array{0: string, 1: string, 2: float}>
     */
    private function penaltyLines(Loan $loan): array
    {
        return collect($this->getJson("/api/loans/{$loan->id}/ledger")->assertOk()->json('data.rows'))
            ->where('kind', 'penalty_charge')
            ->map(fn (array $row): array => [$row['date'], $row['description'], (float) $row['penalty']])
            ->values()
            ->all();
    }

    private function assertSamePenalties(Loan $rate, Loan $bracket): void
    {
        $read = fn (Loan $loan): array => AmortizationSchedule::where('loan_id', $loan->id)->orderBy('period_number')
            ->get(['period_number', 'status', 'penalty_amount', 'penalty_paid', 'penalty_months'])
            ->map(fn (AmortizationSchedule $s): array => [$s->period_number, $s->status, (string) $s->penalty_amount, (string) $s->penalty_paid, (int) $s->penalty_months])
            ->all();

        $this->assertSame($read($rate), $read($bracket));
        $this->assertGreaterThan(0, array_sum(array_map(fn (array $row): float => (float) $row[2], $read($bracket))));
    }

    private function assertSameSummaries(Loan $rate, Loan $bracket): void
    {
        $keys = ['total_outstanding', 'outstanding_penalty', 'payoff_amount', 'advance_presets', 'penalty_months', 'overdue_amount'];
        $read = fn (Loan $loan): array => array_intersect_key(
            $this->getJson("/api/loans/{$loan->id}/summary")->assertOk()->json('data'),
            array_flip($keys),
        );

        $this->assertSame($read($rate), $read($bracket));
    }

    private function period(Loan $loan, int $number): AmortizationSchedule
    {
        return AmortizationSchedule::where('loan_id', $loan->id)->where('period_number', $number)->sole();
    }

    private function assertPenalty(Loan $loan, int $period, float $charged, ?int $months = null): void
    {
        $schedule = $this->period($loan, $period);

        $this->assertEqualsWithDelta($charged, (float) $schedule->penalty_amount, 0.001, "period {$period} penalty charged");

        if ($months !== null) {
            $this->assertSame($months, (int) $schedule->penalty_months, "period {$period} months late");
        }
    }
}
