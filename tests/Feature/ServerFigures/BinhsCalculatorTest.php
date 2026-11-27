<?php

namespace Tests\Feature\ServerFigures;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\User;
use App\Services\LoanService;
use App\Services\LoanTermSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * POST /loans/amortization-calculator/binhs: the Amortization (BINHS) page's
 * Ideal, Worst Case and Custom schedules, worked out on the server in exact
 * maths instead of the browser's floating point.
 *
 * The expected figures in tests/Fixtures/binhs-schedules.json are the
 * frontend's own (src/lib/binhs.ts at frontend 0b05fa7), produced by running
 * its build*Schedule() and sumBinhsRows() functions on each case's input,
 * with one deliberate difference: the last row's principal is the principal
 * less the rounded principal settled before it, so the principal adds up
 * exactly (the browser summed rounded rows and could read ₱99,999.99 for
 * ₱100,000.00). That row's payment and balance follow from it. The due-date
 * cases are ported from src/lib/binhs.test.ts.
 */
class BinhsCalculatorTest extends TestCase
{
    use SetupLendyPH;

    private const URL = '/api/loans/amortization-calculator/binhs';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** @return array<string, array<string, mixed>> */
    private static function fixture(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/binhs-schedules.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{string}> */
    public static function fixtureCases(): array
    {
        $names = array_keys(self::fixture());

        return array_combine($names, array_map(fn (string $name) => [$name], $names));
    }

    /** @return array<string, mixed> */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'principal' => 10000,
            'annual_rate' => 24,
            'term_months' => 3,
            'scbu_per_period' => 100,
            'start_date' => '2026-08-07',
        ], $overrides);
    }

    #[DataProvider('fixtureCases')]
    public function test_every_scenario_equals_the_pages_own_figures(string $case): void
    {
        $expected = self::fixture()[$case];

        $data = $this->postJson(self::URL, $expected['input'])->assertOk()->json('data');

        $this->assertNull($data['prefill']);
        $this->assertNull($data['prefill_notes']);

        foreach (['ideal', 'worst_case', 'custom'] as $scenario) {
            $this->assertSame($expected[$scenario]['rows'], $data['scenarios'][$scenario]['rows'], "{$case} {$scenario} rows");
            $this->assertSame($expected[$scenario]['totals'], $data['scenarios'][$scenario]['totals'], "{$case} {$scenario} totals");
        }
    }

    public function test_the_first_due_date_is_the_start_date_and_dates_step_one_calendar_month(): void
    {
        $dates = fn (array $over, string $scenario = 'ideal') => array_column(
            $this->postJson(self::URL, $this->input($over))->assertOk()->json("data.scenarios.{$scenario}.rows"),
            'due_date',
        );

        $this->assertSame(['2026-08-07', '2026-09-07', '2026-10-07'], $dates([]));
        $this->assertSame(['2026-08-31', '2026-09-30', '2026-10-31'], $dates(['start_date' => '2026-08-31']));
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], $dates(['start_date' => '2027-01-31']));
        $this->assertSame(['2028-01-31', '2028-02-29', '2028-03-31', '2028-04-30'], $dates(['start_date' => '2028-01-31', 'term_months' => 4]));
        $this->assertSame(
            ['2026-09-30', '2026-10-30', '2026-11-30', '2026-12-30', '2027-01-30', '2027-02-28', '2027-03-30'],
            $dates(['start_date' => '2026-09-30', 'term_months' => 7]),
        );
        $this->assertSame(['2027-01-29', '2027-02-28', '2027-03-29'], $dates(['start_date' => '2027-01-29']));
        $this->assertSame(['2028-01-29', '2028-02-29', '2028-03-29'], $dates(['start_date' => '2028-01-29']));

        $expected = ['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'];
        $over = ['start_date' => '2027-01-31', 'term_months' => 4, 'paid_flags' => [true, false, true, false]];
        $this->assertSame($expected, $dates($over, 'worst_case'));
        $this->assertSame($expected, $dates($over, 'custom'));
    }

    public function test_a_custom_scenario_with_every_period_paid_is_the_ideal_principal_and_interest(): void
    {
        $data = $this->postJson(self::URL, $this->input(['paid_flags' => [true, true, true], 'scbu_per_period' => 0]))
            ->assertOk()->json('data.scenarios');

        $this->assertSame(
            array_column($data['ideal']['rows'], 'interest'),
            array_column($data['custom']['rows'], 'interest'),
        );
        $this->assertSame('0.00', $data['custom']['totals']['penalty']);
        $this->assertSame('0.00', end($data['custom']['rows'])['running_balance']);
    }

    public function test_the_ideal_principal_total_equals_the_principal(): void
    {
        // ₱100,000.00 at 24% a year over 12 months: the rounded rows add up
        // to ₱99,999.99, so the last row takes the centavo left.
        $ideal = $this->postJson(self::URL, $this->input([
            'principal' => 100000, 'annual_rate' => 24, 'term_months' => 12, 'scbu_per_period' => 100,
        ]))->assertOk()->json('data.scenarios.ideal');

        $rows = $ideal['rows'];
        $last = array_pop($rows);
        $before = array_sum(array_map(fn (array $row) => (int) round((float) $row['principal'] * 100), $rows));

        $this->assertSame('100000.00', $ideal['totals']['principal']);
        $this->assertSame(number_format((10000000 - $before) / 100, 2, '.', ''), $last['principal']);
        $this->assertSame(
            number_format(((int) round((float) $last['principal'] * 100) + (int) round((float) $last['interest'] * 100) + 10000) / 100, 2, '.', ''),
            $last['total_payment'],
        );
        $this->assertSame('0.00', $last['running_balance']);
    }

    /**
     * The principal a schedule settles adds up to the principal: every paid
     * row's principal, and the last row's (paid or still carried), which
     * takes what the rounded rows before it left.
     */
    #[DataProvider('fixtureCases')]
    public function test_the_principal_settled_adds_up_to_the_principal_in_every_scenario(string $case): void
    {
        $input = self::fixture()[$case]['input'];
        $scenarios = $this->postJson(self::URL, $input)->assertOk()->json('data.scenarios');
        $principal = (int) round((float) $input['principal'] * 100);
        $cents = fn (string $value) => (int) round((float) $value * 100);

        foreach (['ideal' => null, 'worst_case' => [], 'custom' => $input['paid_flags']] as $scenario => $flags) {
            $rows = $scenarios[$scenario]['rows'];
            $settled = 0;

            foreach (array_slice($rows, 0, -1) as $i => $row) {
                if ($flags === null || ($flags[$i] ?? false) === true) {
                    $settled += $cents($row['principal']);
                }
            }

            $this->assertSame($principal, $settled + $cents(end($rows)['principal']), "{$case} {$scenario}");
        }

        $this->assertSame($input['principal'], $scenarios['ideal']['totals']['principal'], $case);
    }

    public function test_a_custom_schedule_paid_in_full_totals_the_principal(): void
    {
        foreach ([[100000, 24, 12], [100000, 18, 6], [12345.67, 0, 5]] as [$principal, $rate, $term]) {
            $custom = $this->postJson(self::URL, $this->input([
                'principal' => $principal, 'annual_rate' => $rate, 'term_months' => $term,
                'paid_flags' => array_fill(0, $term, true),
            ]))->assertOk()->json('data.scenarios.custom');

            $this->assertSame(number_format($principal, 2, '.', ''), $custom['totals']['principal'], "{$principal} {$rate}% {$term}");
            $this->assertSame('0.00', end($custom['rows'])['running_balance']);
        }
    }

    /** @return array<string, array{float, int|float, int}> */
    public static function tinyPrincipals(): array
    {
        return [
            '₱1.50 over 60 months at 0%: ₱0.025 a month' => [1.50, 0, 60],
            '₱1.00 over 60 months at 1% a year' => [1.00, 1, 60],
            '₱1.00 over 60 months at 0%' => [1.00, 0, 60],
        ];
    }

    /**
     * A principal so small that each row's share rounds up (₱0.025 to
     * ₱0.03) never makes the last row negative, and the principal settled
     * still adds up to the principal: no row settles more than is left.
     */
    #[DataProvider('tinyPrincipals')]
    public function test_a_tiny_principal_never_gives_a_negative_row_and_still_adds_up(float $principal, int|float $rate, int $term): void
    {
        $expected = number_format($principal, 2, '.', '');

        foreach ([[], array_fill(0, $term, true)] as $flags) {
            $scenarios = $this->postJson(self::URL, $this->input([
                'principal' => $principal, 'annual_rate' => $rate, 'term_months' => $term,
                'scbu_per_period' => 0, 'paid_flags' => $flags,
            ]))->assertOk()->json('data.scenarios');

            foreach (['ideal', 'worst_case', 'custom'] as $scenario) {
                foreach ($scenarios[$scenario]['rows'] as $row) {
                    foreach (['principal', 'total_payment', 'running_balance'] as $column) {
                        $this->assertGreaterThanOrEqual(0, (float) $row[$column], "{$scenario} period {$row['period']} {$column}");
                    }
                }
            }

            $this->assertSame($expected, $scenarios['ideal']['totals']['principal']);
            $this->assertSame('0.00', end($scenarios['ideal']['rows'])['running_balance']);
            $this->assertSame($expected, end($scenarios['worst_case']['rows'])['principal']);

            if ($flags !== []) {
                $this->assertSame($expected, $scenarios['custom']['totals']['principal']);
            }
        }
    }

    public function test_an_exact_half_centavo_rounds_up_where_the_browser_fell_short(): void
    {
        // Interest-free, ₱8,859,035.65 over 36 months: 18 periods carried and
        // settled together are exactly half the principal, ₱4,429,517.825,
        // which rounds half up to ₱4,429,517.83. The page's floating point
        // summed it a hair short and showed ₱4,429,517.82.
        $flags = array_map(fn (int $i) => $i === 17, range(0, 35));

        $data = $this->postJson(self::URL, [
            'principal' => 8859035.65, 'annual_rate' => 0, 'term_months' => 36,
            'scbu_per_period' => 0, 'start_date' => '2026-01-31', 'paid_flags' => $flags,
        ])->assertOk()->json('data.scenarios');

        $this->assertSame('4429517.83', $data['custom']['rows'][17]['principal']);
        $this->assertSame('4429517.83', $data['custom']['rows'][17]['total_payment']);
        $this->assertSame('4429517.83', $data['worst_case']['rows'][17]['principal']);
        $this->assertSame('8859035.65', $data['worst_case']['rows'][35]['principal']);
    }

    public function test_interest_that_is_exactly_a_half_centavo_rounds_up(): void
    {
        // 100% a year is 1/12 a month, and ₱29,971,651.74 ÷ 12 is exactly
        // ₱2,497,637.645 of first-month interest.
        $data = $this->postJson(self::URL, [
            'principal' => 29971651.74, 'annual_rate' => 100, 'term_months' => 7,
            'scbu_per_period' => 264.18, 'start_date' => '2026-01-31',
        ])->assertOk()->json('data.scenarios');

        foreach (['ideal', 'worst_case', 'custom'] as $scenario) {
            $this->assertSame('2497637.65', $data[$scenario]['rows'][0]['interest'], $scenario);
        }
    }

    public function test_it_needs_loans_view(): void
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $this->actingAs($user);

        $this->postJson(self::URL, $this->input())->assertForbidden();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badInputs(): array
    {
        return [
            'no principal' => [['principal' => null], 'principal'],
            'principal below ₱1.00' => [['principal' => 0.99], 'principal'],
            'principal above ₱100,000,000.00' => [['principal' => 100000000.01], 'principal'],
            'principal with 3 decimals' => [['principal' => 1000.005], 'principal'],
            'principal not a number' => [['principal' => 'lots'], 'principal'],
            'negative rate' => [['annual_rate' => -0.01], 'annual_rate'],
            'rate above 100' => [['annual_rate' => 100.0001], 'annual_rate'],
            'rate with 5 decimals' => [['annual_rate' => 12.00001], 'annual_rate'],
            'no term' => [['term_months' => null], 'term_months'],
            'term of 0' => [['term_months' => 0], 'term_months'],
            'term above 60' => [['term_months' => 61], 'term_months'],
            'term not whole' => [['term_months' => 1.5], 'term_months'],
            'no start date' => [['start_date' => null], 'start_date'],
            'start date not a date' => [['start_date' => 'not-a-date'], 'start_date'],
            'start date that does not exist' => [['start_date' => '2026-02-30'], 'start_date'],
            'start date with a time' => [['start_date' => '2026-08-07 00:00:00'], 'start_date'],
            'negative build-up' => [['scbu_per_period' => -1], 'scbu_per_period'],
            'build-up with 3 decimals' => [['scbu_per_period' => 1.005], 'scbu_per_period'],
            'build-up too large' => [['scbu_per_period' => 10000000], 'scbu_per_period'],
            'paid flags not a list' => [['paid_flags' => 'yes'], 'paid_flags'],
            'a paid flag not a yes or no' => [['paid_flags' => [true, 'maybe']], 'paid_flags.1'],
            'more paid flags than periods' => [['paid_flags' => [true, true, true, true]], 'paid_flags'],
            'more than 60 paid flags' => [['term_months' => 60, 'paid_flags' => array_fill(0, 61, true)], 'paid_flags'],
            'paid flags keyed by period, not a list' => [['paid_flags' => ['1' => true, '2' => false]], 'paid_flags'],
            'loan id not a number' => [['loan_id' => 'abc'], 'loan_id'],
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    #[DataProvider('badInputs')]
    public function test_a_bad_input_answers_422(array $overrides, string $field): void
    {
        $this->postJson(self::URL, $this->input($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public function test_the_limits_themselves_are_accepted(): void
    {
        foreach ([
            ['principal' => 1, 'annual_rate' => 0, 'term_months' => 1, 'scbu_per_period' => 0],
            ['principal' => 100000000, 'annual_rate' => 100, 'term_months' => 60, 'scbu_per_period' => 9999999.99, 'paid_flags' => array_fill(0, 60, false)],
            ['annual_rate' => 12.3456],
        ] as $over) {
            $rows = $this->postJson(self::URL, $this->input($over))->assertOk()->json('data.scenarios.ideal.rows');
            $this->assertCount($over['term_months'] ?? 3, $rows);
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function loan(array $attributes, ?Branch $branch = null): Loan
    {
        $branch ??= $this->branch;

        return Loan::factory()->create(array_merge([
            'branch_id' => $branch->id,
            'borrower_id' => Borrower::factory()->create(['branch_id' => $branch->id])->id,
            'principal_amount' => 60000,
            'interest_rate' => 3,
            'interest_rate_frequency' => 'monthly',
            'term' => 6,
            'term_unit' => 'months',
            'start_date' => '2026-03-15',
        ], $attributes));
    }

    public function test_a_loan_id_alone_answers_its_prefill_with_the_rate_a_year(): void
    {
        $loan = $this->loan([]);

        $data = $this->postJson(self::URL, ['loan_id' => $loan->id])->assertOk()->json('data');

        $this->assertSame([
            'loan_id' => $loan->id,
            'principal' => '60000.00',
            'annual_rate' => '36',
            'term_months' => 6,
            'start_date' => '2026-04-15',
        ], $data['prefill']);
        $this->assertSame([], $data['prefill_notes']);
        $this->assertNull($data['scenarios']);
    }

    public function test_the_prefill_start_date_is_the_loans_first_due_date_from_its_schedule(): void
    {
        $loan = $this->loan(['start_date' => '2026-03-15']);

        foreach ([[3, '2026-06-20'], [1, '2026-04-20'], [2, '2026-05-20']] as [$period, $dueDate]) {
            AmortizationSchedule::factory()->create(['loan_id' => $loan->id, 'period_number' => $period, 'due_date' => $dueDate]);
        }

        $this->postJson(self::URL, ['loan_id' => $loan->id])
            ->assertOk()
            ->assertJsonPath('data.prefill.start_date', '2026-04-20');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function anchoredFirstDueDates(): array
    {
        return [
            'monthly, one calendar month on' => [['start_date' => '2026-03-15'], '2026-04-15'],
            'monthly from the 31st, capped at February\'s end' => [['start_date' => '2026-01-31'], '2026-02-28'],
            'weekly on a days term' => [['frequency' => 'weekly', 'term' => 45, 'term_unit' => 'days', 'start_date' => '2026-03-15'], '2026-03-22'],
            'semi-monthly on a months term' => [['frequency' => 'semi_monthly', 'start_date' => '2026-03-15'], '2026-03-30'],
            'one payment at maturity' => [['frequency' => 'upon_maturity', 'term' => 3, 'start_date' => '2026-01-31', 'maturity_date' => '2026-04-30'], '2026-04-30'],
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    #[DataProvider('anchoredFirstDueDates')]
    public function test_without_a_schedule_the_prefill_start_date_is_the_anchored_first_due_date(array $attributes, string $firstDueDate): void
    {
        $loan = $this->loan($attributes);
        $this->assertSame(0, $loan->amortizationSchedules()->count());

        $this->postJson(self::URL, ['loan_id' => $loan->id])
            ->assertOk()
            ->assertJsonPath('data.prefill.start_date', $firstDueDate);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function draftShapes(): array
    {
        return [
            'one payment at maturity on a 6-month term' => [['frequency' => 'upon_maturity', 'term' => 6, 'start_date' => '2026-01-31']],
            'one payment at maturity on a 45-day term' => [['frequency' => 'upon_maturity', 'term' => 45, 'term_unit' => 'days', 'start_date' => '2026-01-31']],
            'interest-only months, principal at maturity' => [['interest_method' => 'upon_maturity', 'frequency' => 'monthly', 'term' => 6, 'start_date' => '2026-01-31']],
            'diminishing monthly from the 31st' => [['interest_method' => 'diminishing', 'frequency' => 'monthly', 'term' => 6, 'start_date' => '2026-01-31']],
            'straight weekly on a days term' => [['frequency' => 'weekly', 'term' => 45, 'term_unit' => 'days', 'start_date' => '2026-03-15']],
            'straight semi-monthly' => [['frequency' => 'semi_monthly', 'term' => 6, 'start_date' => '2026-03-15']],
        ];
    }

    /**
     * Without a schedule, the prefill date is the first due date the release
     * will save: the first row of the engine's own schedule
     * (LoanService::buildAmortizationPreview()), which is what release
     * stores. A single payment at maturity is one row on the maturity date,
     * whatever the term's length.
     *
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('draftShapes')]
    public function test_without_a_schedule_the_prefill_date_is_the_first_row_the_engine_will_save(array $attributes): void
    {
        $attributes += ['term_unit' => 'months', 'interest_method' => 'straight'];
        $attributes['maturity_date'] = LoanTermSchedule::maturityDate(
            Carbon::parse($attributes['start_date']), $attributes['term'], $attributes['term_unit'], $attributes['frequency'],
        )->toDateString();
        $loan = $this->loan($attributes);

        $engineFirst = app(LoanService::class)->buildAmortizationPreview($loan->fresh())[0]['due_date'];

        $this->postJson(self::URL, ['loan_id' => $loan->id])
            ->assertOk()
            ->assertJsonPath('data.prefill.start_date', $engineFirst);

        if ($attributes['frequency'] === 'upon_maturity') {
            $this->assertSame($attributes['maturity_date'], $engineFirst);
        }
    }

    public function test_a_loan_id_with_inputs_answers_both(): void
    {
        $loan = $this->loan([]);

        $data = $this->postJson(self::URL, $this->input(['loan_id' => $loan->id]))->assertOk()->json('data');

        $this->assertSame('36', $data['prefill']['annual_rate']);
        $this->assertCount(3, $data['scenarios']['ideal']['rows']);
    }

    /** @return array<string, array{string, string, string, string|null}> */
    public static function ratePeriods(): array
    {
        return [
            'monthly × 12' => ['monthly', '1.1234', 'months', '13.4808'],
            'annual × 1' => ['annual', '18.5', 'months', '18.5'],
            'semi-monthly × 24' => ['semi_monthly', '1.0625', 'months', '25.5'],
            'daily × 360' => ['daily', '0.0125', 'months', '4.5'],
            'weekly × 360 ÷ 7, exact' => ['weekly', '0.7', 'months', '36'],
            'weekly × 360 ÷ 7, not a finite decimal' => ['weekly', '1', 'months', null],
            'bi-weekly × 360 ÷ 14, exact' => ['bi_weekly', '1.4', 'months', '36'],
        ];
    }

    public function test_a_loan_id_with_only_some_inputs_needs_the_rest(): void
    {
        $loan = $this->loan([]);

        $this->postJson(self::URL, ['loan_id' => $loan->id, 'principal' => 5000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['annual_rate', 'term_months', 'start_date'])
            ->assertJsonMissingValidationErrors(['principal', 'loan_id']);
    }

    #[DataProvider('ratePeriods')]
    public function test_the_prefill_rate_is_exact_and_never_rounded(string $period, string $rate, string $unit, ?string $annual): void
    {
        $loan = $this->loan(['interest_rate' => $rate, 'interest_rate_frequency' => $period, 'term_unit' => $unit]);

        $this->postJson(self::URL, ['loan_id' => $loan->id])
            ->assertOk()
            ->assertJsonPath('data.prefill.annual_rate', $annual);
    }

    public function test_a_days_term_prefills_no_term(): void
    {
        $loan = $this->loan(['term' => 45, 'term_unit' => 'days']);

        $this->postJson(self::URL, ['loan_id' => $loan->id])
            ->assertOk()
            ->assertJsonPath('data.prefill.term_months', null)
            ->assertJsonPath('data.prefill_notes', ["Term left out: the loan's term is 45 days, and the calculator takes months."]);
    }

    /** @return array<string, array{array<string, mixed>, string|null, int|null, list<string>}> */
    public static function prefillsTheCalculatorRefuses(): array
    {
        return [
            '9% a month is 108% a year' => [
                ['interest_rate' => '9', 'interest_rate_frequency' => 'monthly'], null, 6,
                ["Annual rate left out: 9% a month is 108% a year, above the calculator's limit of 100%."],
            ],
            '1% a day is 360% a year' => [
                ['interest_rate' => '1', 'interest_rate_frequency' => 'daily'], null, 6,
                ["Annual rate left out: 1% a day is 360% a year, above the calculator's limit of 100%."],
            ],
            '1% a week has no exact rate a year' => [
                ['interest_rate' => '1', 'interest_rate_frequency' => 'weekly'], null, 6,
                ['Annual rate left out: 1% a week has no exact rate a year. Enter the annual rate.'],
            ],
            '72 months is over 60' => [
                ['term' => 72], '36', null,
                ["Term left out: the loan's term of 72 months is outside the calculator's 1 to 60 months."],
            ],
            'a term of 0 months' => [
                ['term' => 0], '36', null,
                ["Term left out: the loan's term of 0 months is outside the calculator's 1 to 60 months."],
            ],
            'both left out' => [
                ['interest_rate' => '8.5', 'interest_rate_frequency' => 'monthly', 'term' => 61], null, null,
                [
                    "Annual rate left out: 8.5% a month is 102% a year, above the calculator's limit of 100%.",
                    "Term left out: the loan's term of 61 months is outside the calculator's 1 to 60 months.",
                ],
            ],
        ];
    }

    /**
     * A prefill never suggests a value the calculator would refuse: it
     * leaves the rate or the term out and says why, in words.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $notes
     */
    #[DataProvider('prefillsTheCalculatorRefuses')]
    public function test_a_prefill_the_calculator_would_refuse_is_left_out_with_a_note(array $attributes, ?string $annualRate, ?int $termMonths, array $notes): void
    {
        $loan = $this->loan($attributes);

        $data = $this->postJson(self::URL, ['loan_id' => $loan->id])->assertOk()->json('data');

        $this->assertSame($annualRate, $data['prefill']['annual_rate']);
        $this->assertSame($termMonths, $data['prefill']['term_months']);
        $this->assertSame($notes, $data['prefill_notes']);
    }

    public function test_a_prefill_at_the_limits_is_kept(): void
    {
        foreach ([
            [['interest_rate' => '100', 'interest_rate_frequency' => 'annual', 'term' => 60], '100', 60],
            [['interest_rate' => '8.3333', 'interest_rate_frequency' => 'monthly', 'term' => 1], '99.9996', 1],
        ] as [$attributes, $annualRate, $termMonths]) {
            $data = $this->postJson(self::URL, ['loan_id' => $this->loan($attributes)->id])->assertOk()->json('data');

            $this->assertSame($annualRate, $data['prefill']['annual_rate']);
            $this->assertSame($termMonths, $data['prefill']['term_months']);
            $this->assertSame([], $data['prefill_notes']);
        }
    }

    public function test_another_branchs_loan_or_a_missing_one_answers_404(): void
    {
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $hidden = $this->loan([], $far);

        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole('loan_officer');
        $this->actingAs($user);

        $this->postJson(self::URL, ['loan_id' => $hidden->id])->assertNotFound();
        $this->postJson(self::URL, $this->input(['loan_id' => $hidden->id]))->assertNotFound();
        $this->postJson(self::URL, ['loan_id' => 999999])->assertNotFound();

        $visible = $this->loan([]);
        $this->postJson(self::URL, ['loan_id' => $visible->id])->assertOk()->assertJsonPath('data.prefill.loan_id', $visible->id);
    }

    public function test_it_writes_nothing(): void
    {
        $tables = ['loans', 'amortization_schedules', 'audit_logs'];
        $before = array_map(fn (string $t) => DB::table($t)->count(), $tables);

        $this->postJson(self::URL, $this->input(['paid_flags' => [false, true, false]]))->assertOk();

        $this->assertSame($before, array_map(fn (string $t) => DB::table($t)->count(), $tables));
    }
}
