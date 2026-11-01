<?php

namespace Tests\Feature\ServerFigures;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * POST /loans/amortization-calculator: the Amortization Calculator page's
 * schedule, built by the engine a release writes (LoanService::
 * buildAmortizationPreview) instead of the browser's own maths (whole-peso
 * rounding, a monthly rate every period, no semi-monthly, upon-maturity or
 * days terms). Stateless: no product and no loan, so it needs only loans:view.
 */
class AmortizationCalculatorTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function calculatorCases(): array
    {
        return [
            'straight, monthly, 6 months at 3% a month' => [[
                'principal_amount' => 50000, 'interest_rate' => 3, 'interest_rate_frequency' => 'monthly',
                'interest_method' => 'straight', 'term' => 6, 'term_unit' => 'months', 'frequency' => 'monthly',
                'start_date' => '2026-01-31',
            ]],
            'diminishing, semi-monthly, centavo figures' => [[
                'principal_amount' => 12345.67, 'interest_rate' => 2.75, 'interest_rate_frequency' => 'monthly',
                'interest_method' => 'diminishing', 'term' => 4, 'term_unit' => 'months', 'frequency' => 'semi_monthly',
                'start_date' => '2026-03-15',
            ]],
            'upon maturity method, weekly, a days term at a weekly rate' => [[
                'principal_amount' => 60000, 'interest_rate' => 1, 'interest_rate_frequency' => 'weekly',
                'interest_method' => 'upon_maturity', 'term' => 45, 'term_unit' => 'days', 'frequency' => 'weekly',
                'start_date' => '2026-01-15',
            ]],
            'a single payment at maturity' => [[
                'principal_amount' => 70000, 'interest_rate' => 1, 'interest_rate_frequency' => 'weekly',
                'interest_method' => 'straight', 'term' => 1, 'term_unit' => 'months', 'frequency' => 'upon_maturity',
                'start_date' => '2026-04-27',
            ]],
        ];
    }

    /**
     * POST /loans/preview's amortization for a product carrying the same
     * settings: the schedule a release of that loan writes.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function previewFor(array $input): array
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => $input['interest_rate'],
            'interest_rate_frequency' => $input['interest_rate_frequency'],
            'interest_method' => $input['interest_method'],
            'term' => $input['term'],
            'min_term' => 1,
            'max_term' => $input['term'],
            'term_unit' => $input['term_unit'],
            'frequency' => $input['frequency'],
            'min_amount' => 1000,
        ]);

        return $this->postJson('/api/loans/preview', [
            'loan_product_id' => $product->id,
            'principal_amount' => $input['principal_amount'],
            'interest_rate' => $input['interest_rate'],
            'term' => $input['term'],
            'frequency' => $input['frequency'],
            'start_date' => $input['start_date'],
            'scb_amount' => $input['scb_amount'] ?? 0,
        ])->assertOk()->json('data.amortization');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('calculatorCases')]
    public function test_its_schedule_equals_the_loan_form_preview_for_the_same_settings(array $input): void
    {
        $calculated = $this->postJson('/api/loans/amortization-calculator', $input)->assertOk()->json('data');
        $expected = $this->previewFor($input);

        $this->assertNotNull($expected);
        $this->assertSame($expected['maturity_date'], $calculated['maturity_date']);
        $this->assertSame($input['interest_method'], $calculated['interest_method']);
        $this->assertEquals($expected['rows'], $calculated['rows']);
        $this->assertEquals($expected['totals'], $calculated['totals']);
    }

    public function test_it_adds_the_share_capital_build_up_to_each_period_as_the_preview_does(): void
    {
        $input = self::calculatorCases()['straight, monthly, 6 months at 3% a month'][0] + ['scb_amount' => 100.50];

        $calculated = $this->postJson('/api/loans/amortization-calculator', $input)->assertOk()->json('data');
        $expected = $this->previewFor($input);

        $this->assertEquals($expected['rows'], $calculated['rows']);
        $this->assertEquals(603, $calculated['totals']['share_capital_build_up']);
        $this->assertEquals($expected['totals'], $calculated['totals']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function methods(): array
    {
        return ['straight' => ['straight'], 'diminishing' => ['diminishing'], 'upon maturity' => ['upon_maturity']];
    }

    #[DataProvider('methods')]
    public function test_every_method_with_build_up_equals_the_loan_form_preview(string $method): void
    {
        $input = ['interest_method' => $method, 'scb_amount' => 100.50] + self::calculatorCases()['diminishing, semi-monthly, centavo figures'][0];

        $calculated = $this->postJson('/api/loans/amortization-calculator', $input)->assertOk()->json('data');
        $expected = $this->previewFor($input);

        $this->assertEquals($expected['rows'], $calculated['rows']);
        $this->assertEquals($expected['totals'], $calculated['totals']);
        $this->assertGreaterThan(0, $calculated['totals']['share_capital_build_up']);
    }

    public function test_with_compare_the_main_schedule_is_the_chosen_method_s_comparison_entry(): void
    {
        $input = ['interest_method' => 'diminishing', 'compare' => true] + self::calculatorCases()['diminishing, semi-monthly, centavo figures'][0];

        $data = $this->postJson('/api/loans/amortization-calculator', $input)->assertOk()->json('data');
        $chosen = $data['comparison']['diminishing'];

        $this->assertSame($chosen['rows'], $data['rows']);
        $this->assertSame($chosen['totals'], $data['totals']);
        $this->assertSame($chosen['maturity_date'], $data['maturity_date']);
        $this->assertSame('diminishing', $data['interest_method']);
    }

    /**
     * A daily schedule over a days term: one instalment per day.
     *
     * @return array<string, mixed>
     */
    private function dailyTerm(int $days): array
    {
        return [
            'principal_amount' => 10000, 'interest_rate' => 0.1, 'interest_rate_frequency' => 'daily',
            'interest_method' => 'straight', 'term' => $days, 'term_unit' => 'days', 'frequency' => 'daily',
            'start_date' => '2026-01-01',
        ];
    }

    public function test_a_schedule_of_1000_instalments_is_built(): void
    {
        $this->postJson('/api/loans/amortization-calculator', $this->dailyTerm(1000))
            ->assertOk()
            ->assertJsonCount(1000, 'data.rows');
    }

    public function test_a_schedule_of_1001_instalments_is_refused_naming_the_limit(): void
    {
        $this->postJson('/api/loans/amortization-calculator', $this->dailyTerm(1001))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term' => 'at most 1,000']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusedInputs(): array
    {
        $base = self::calculatorCases()['straight, monthly, 6 months at 3% a month'][0];

        return [
            'a months term over 30 years' => [['term' => 361, 'term_unit' => 'months'] + $base, 'term'],
            'a days term over 30 years' => [['term' => 10951, 'term_unit' => 'days', 'frequency' => 'upon_maturity'] + $base, 'term'],
            'build-up over the column maximum' => [['scb_amount' => 10000000000] + $base, 'scb_amount'],
            'build-up with three decimals' => [['scb_amount' => 100.505] + $base, 'scb_amount'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('refusedInputs')]
    public function test_it_refuses_out_of_bounds_inputs(array $input, string $field): void
    {
        $this->postJson('/api/loans/amortization-calculator', $input)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public function test_it_is_throttled_per_user(): void
    {
        $input = self::calculatorCases()['a single payment at maturity'][0];

        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/loans/amortization-calculator', $input)->assertOk();
        }

        $this->postJson('/api/loans/amortization-calculator', $input)->assertTooManyRequests();
    }

    public function test_compare_returns_the_schedule_under_every_interest_method(): void
    {
        $input = self::calculatorCases()['diminishing, semi-monthly, centavo figures'][0];

        $comparison = $this->postJson('/api/loans/amortization-calculator', $input + ['compare' => true])
            ->assertOk()
            ->json('data.comparison');

        $this->assertEqualsCanonicalizing(['straight', 'diminishing', 'upon_maturity'], array_keys($comparison));

        foreach (['straight', 'diminishing', 'upon_maturity'] as $method) {
            $single = $this->postJson('/api/loans/amortization-calculator', ['interest_method' => $method] + $input)
                ->assertOk()
                ->json('data');

            $this->assertEquals($single['rows'], $comparison[$method]['rows'], $method);
            $this->assertEquals($single['totals'], $comparison[$method]['totals'], $method);
        }
    }

    public function test_it_writes_nothing(): void
    {
        $loans = Loan::count();
        $products = LoanProduct::count();
        $schedules = AmortizationSchedule::count();

        $this->postJson('/api/loans/amortization-calculator', self::calculatorCases()['a single payment at maturity'][0] + ['compare' => true])
            ->assertOk();

        $this->assertSame($loans, Loan::count());
        $this->assertSame($products, LoanProduct::count());
        $this->assertSame($schedules, AmortizationSchedule::count());
    }

    public function test_it_validates_its_inputs(): void
    {
        $this->postJson('/api/loans/amortization-calculator', ['interest_method' => 'compound'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['principal_amount', 'interest_rate', 'interest_method', 'term', 'frequency', 'start_date']);
    }

    public function test_a_user_without_loans_view_gets_403(): void
    {
        $this->actingAs(User::factory()->create(['branch_id' => $this->branch->id]));

        $this->postJson('/api/loans/amortization-calculator', self::calculatorCases()['a single payment at maturity'][0])
            ->assertForbidden();
    }
}
