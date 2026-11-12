<?php

namespace Tests\Feature;

use App\Enums\InterestRateFrequency;
use App\Enums\LoanFrequency;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\AmortizationCalculatorService;
use App\Services\LoanService;
use App\Services\LoanTermSchedule;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A rate quoted per year (`interest_rate_frequency` = `annual`), on the same
 * 30-day-month convention as every other period: a year is 360 days, so an
 * annual rate is 12 times the monthly rate that charges the same. The factor
 * is worked out exactly (rate × days ÷ 36,000), so 18% a year charges what
 * 1.5% a month does, to the centavo. The other periods keep the arithmetic
 * every existing loan was charged with.
 */
class AnnualInterestRateTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_annual_is_a_rate_period_and_never_a_payment_frequency(): void
    {
        $this->assertContains('annual', InterestRateFrequency::values());
        $this->assertNotContains('annual', LoanFrequency::values());
        $this->assertSame(360, LoanTermSchedule::PERIOD_DAYS['annual']);

        $this->postJson('/api/loan-products', $this->productPayload(['frequency' => 'annual']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('frequency');
        $this->postJson('/api/loan-products', $this->productPayload(['frequencies' => ['monthly', 'annual']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('frequencies.1');
        $this->postJson('/api/loans/amortization-calculator', $this->calculatorInput(['frequency' => 'annual']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('frequency');
    }

    public function test_a_product_can_quote_its_rate_per_year_and_a_new_loan_copies_it(): void
    {
        $id = $this->postJson('/api/loan-products', $this->productPayload([
            'interest_rate' => 18,
            'interest_rate_frequency' => 'annual',
        ]))->assertCreated()->json('data.id');

        $this->assertSame(InterestRateFrequency::Annual, LoanProduct::findOrFail($id)->interest_rate_frequency);

        $loan = $this->draftLoan(['interest_rate' => 18, 'interest_rate_frequency' => 'annual']);

        $this->assertSame(InterestRateFrequency::Annual, $loan->interest_rate_frequency);
        $this->getJson("/api/loans/{$loan->id}")->assertOk()->assertJsonPath('data.interest_rate_frequency', 'annual');
    }

    public function test_the_annual_factor_is_exact_and_the_other_periods_are_unchanged(): void
    {
        $this->assertSame(0.015, LoanTermSchedule::rateForDays(18, 'annual', 30));
        $this->assertSame(0.18, LoanTermSchedule::rateForDays(18, 'annual', 360));
        $this->assertSame(0.0075, LoanTermSchedule::rateForDays(18, 'annual', 15));
        $this->assertSame(0.033333, LoanTermSchedule::rateForDays(39.9996, 'annual', 30));
        $this->assertSame(0.0, LoanTermSchedule::rateForDays(0, 'annual', 30));

        $this->assertSame(0.03, LoanTermSchedule::rateForDays(3, 'monthly', 30));
        $this->assertSame(3 / 100 * (15 / 30), LoanTermSchedule::rateForDays(3, 'monthly', 15));
        $this->assertSame(1 / 100 * (30 / 7), LoanTermSchedule::rateForDays(1, 'weekly', 30));
    }

    /**
     * Every principal, term, rate and shape on the grid gives the same
     * schedule and totals, to the centavo, at 12r a year as at r a month.
     */
    public function test_an_annual_rate_charges_exactly_what_its_monthly_twelfth_does(): void
    {
        $calculator = app(AmortizationCalculatorService::class);
        $shapes = [
            'straight' => ['interest_method' => 'straight', 'frequency' => 'monthly'],
            'diminishing' => ['interest_method' => 'diminishing', 'frequency' => 'monthly'],
            'upon maturity' => ['interest_method' => 'upon_maturity', 'frequency' => 'monthly'],
            'single payment' => ['interest_method' => 'straight', 'frequency' => 'upon_maturity'],
        ];
        $compared = 0;

        foreach ($shapes as $shapeName => $shape) {
            foreach ([1000, 12345.67, 50000, 333333.33, 1000000] as $principal) {
                foreach ([1, 6, 12, 36] as $term) {
                    foreach (['1', '1.5', '2.75', '3.3333'] as $monthly) {
                        $input = $this->calculatorInput($shape + ['principal_amount' => $principal, 'term' => $term]);
                        $annual = bcmul($monthly, '12', 4);

                        $expected = $calculator->calculate(['interest_rate' => $monthly, 'interest_rate_frequency' => 'monthly'] + $input);
                        $actual = $calculator->calculate(['interest_rate' => $annual, 'interest_rate_frequency' => 'annual'] + $input);

                        $case = "{$shapeName}, ₱{$principal}, {$term} months, {$monthly}% a month = {$annual}% a year";
                        $this->assertSame($expected['rows'], $actual['rows'], $case);
                        $this->assertSame($expected['totals'], $actual['totals'], $case);
                        $this->assertSame($expected['maturity_date'], $actual['maturity_date'], $case);
                        $compared++;
                    }
                }
            }
        }

        $this->assertSame(320, $compared);
    }

    /**
     * Instalments that aren't 30 days: weekly and semi-monthly schedules
     * (7- and 15-day periods, and a shorter last one), and a single payment
     * at maturity on a days term. Each annual case equals its monthly twin
     * to the centavo, except where the monthly twin's figure is exactly a
     * half-centavo: the annual path works it out exactly and rounds it up,
     * the monthly float path can land a hair below and round it down. Every
     * such row is checked to be that tie, and nothing else differs.
     */
    public function test_an_annual_rate_matches_its_monthly_twin_on_other_instalment_lengths(): void
    {
        $calculator = app(AmortizationCalculatorService::class);
        $shapes = [];

        foreach (['weekly', 'semi_monthly'] as $frequency) {
            foreach (['straight', 'diminishing', 'upon_maturity'] as $method) {
                foreach ([1, 3, 12] as $months) {
                    $shapes[] = ['interest_method' => $method, 'frequency' => $frequency, 'term' => $months, 'term_unit' => 'months'];
                }

                $shapes[] = ['interest_method' => $method, 'frequency' => $frequency, 'term' => 100, 'term_unit' => 'days'];
            }
        }

        foreach (['straight', 'upon_maturity'] as $method) {
            foreach ([7, 15, 45, 90, 100, 365] as $days) {
                $shapes[] = ['interest_method' => $method, 'frequency' => 'upon_maturity', 'term' => $days, 'term_unit' => 'days'];
            }
        }

        $compared = 0;

        foreach ($shapes as $shape) {
            foreach ([1000, 12345.67, 50000, 333333.33, 1000000] as $principal) {
                foreach (['1', '1.5', '2.75', '3.3333'] as $monthly) {
                    $input = $this->calculatorInput($shape + ['principal_amount' => $principal]);
                    $annual = bcmul($monthly, '12', 4);

                    $expected = $calculator->calculate(['interest_rate' => $monthly, 'interest_rate_frequency' => 'monthly'] + $input);
                    $actual = $calculator->calculate(['interest_rate' => $annual, 'interest_rate_frequency' => 'annual'] + $input);

                    $case = "{$shape['interest_method']}, {$shape['frequency']}, {$shape['term']} {$shape['term_unit']}, ₱{$principal}, {$monthly}% a month = {$annual}% a year";
                    if ($expected['rows'] === $actual['rows']) {
                        $this->assertSame($expected['totals'], $actual['totals'], $case);
                    } else {
                        $ties[] = $case;
                        $this->assertOnlyHalfCentavoTies($input, $monthly, $expected['rows'], $actual['rows'], $case);
                    }

                    $compared++;
                }
            }
        }

        $this->assertSame(count($shapes) * 20, $compared);
        // Both on the 10-day last period of a 100-day semi-monthly loan at 1%
        // a month: ₱48,334.50 and ₱145,003.50 ÷ 300 are exactly ₱161.115 and
        // ₱483.345.
        $this->assertSame([
            'diminishing, semi_monthly, 100 days, ₱333333.33, 1% a month = 12.0000% a year',
            'diminishing, semi_monthly, 100 days, ₱1000000, 1% a month = 12.0000% a year',
        ], $ties ?? []);
    }

    public function test_the_calculator_endpoint_takes_an_annual_rate(): void
    {
        $annual = $this->postJson('/api/loans/amortization-calculator', $this->calculatorInput([
            'interest_rate' => 18, 'interest_rate_frequency' => 'annual',
        ]))->assertOk()->json('data');
        $monthly = $this->postJson('/api/loans/amortization-calculator', $this->calculatorInput([
            'interest_rate' => 1.5, 'interest_rate_frequency' => 'monthly',
        ]))->assertOk()->json('data');

        $this->assertEquals(750, $annual['rows'][0]['interest_due']);
        $this->assertSame($monthly['rows'], $annual['rows']);
        $this->assertSame($monthly['totals'], $annual['totals']);
    }

    public function test_a_saved_annual_loan_has_the_schedule_of_its_monthly_twin(): void
    {
        $annual = $this->draftLoan(['interest_rate' => 18, 'interest_rate_frequency' => 'annual', 'interest_method' => 'diminishing']);
        $monthly = $this->draftLoan(['interest_rate' => 1.5, 'interest_rate_frequency' => 'monthly', 'interest_method' => 'diminishing']);

        $a = $this->getJson("/api/loans/{$annual->id}/amortization-preview")->assertOk()->json('data');
        $m = $this->getJson("/api/loans/{$monthly->id}/amortization-preview")->assertOk()->json('data');

        $this->assertNotEmpty($a);
        $this->assertSame($m, $a);
    }

    public function test_extension_interest_on_an_annual_loan_equals_its_monthly_twin(): void
    {
        $this->travelTo('2026-06-10 09:00:00');

        $loans = [];

        foreach (['annual' => 36, 'monthly' => 3] as $frequency => $rate) {
            $loans[$frequency] = $this->createReleasedLoan([
                'product' => [
                    'interest_method' => 'upon_maturity',
                    'term' => 1,
                    'frequency' => 'monthly',
                    'interest_rate' => $rate,
                    'interest_rate_frequency' => $frequency,
                ],
                'principal_amount' => 60000,
                'start_date' => '2026-05-27',
            ]);
        }

        $previews = array_map(
            fn (Loan $loan) => $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=defer")->assertOk()->json('data'),
            $loans,
        );

        $this->assertSame($previews['monthly'], $previews['annual']);

        foreach ($loans as $loan) {
            $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();
        }

        $newPeriod = fn (Loan $loan) => $loan->amortizationSchedules()->orderByDesc('period_number')->first();

        // Deferred: the unpaid ₱1,800.00 plus one fresh cycle at 3% of ₱60,000.00.
        $this->assertEquals(3600, $newPeriod($loans['monthly'])->interest_due);
        $this->assertSame((string) $newPeriod($loans['monthly'])->interest_due, (string) $newPeriod($loans['annual'])->interest_due);
    }

    public function test_the_disclosure_states_an_annual_rate_and_the_effective_rate_of_its_monthly_twin(): void
    {
        $annual = $this->approvedLoan(['interest_rate' => 18, 'interest_rate_frequency' => 'annual']);
        $monthly = $this->approvedLoan(['interest_rate' => 1.5, 'interest_rate_frequency' => 'monthly']);

        $a = $this->getJson("/api/loans/{$annual->id}/disclosure")->assertOk()->json('data.rates');
        $m = $this->getJson("/api/loans/{$monthly->id}/disclosure")->assertOk()->json('data.rates');

        $this->assertEquals(18, $a['contractual_rate']);
        $this->assertSame('annual', $a['rate_frequency']);
        $this->assertEquals(18, $a['nominal_annual_rate']);
        $this->assertEquals($m['nominal_annual_rate'], $a['nominal_annual_rate']);
        $this->assertNotNull($a['effective_rate_annual']);
        $this->assertSame($m['effective_rate_monthly'], $a['effective_rate_monthly']);
        $this->assertSame($m['effective_rate_annual'], $a['effective_rate_annual']);
    }

    public function test_the_statement_of_account_reads_an_annual_rate_per_year(): void
    {
        $loan = $this->createReleasedLoan(['product' => ['interest_rate' => 18, 'interest_rate_frequency' => 'annual']]);

        $this->getJson("/api/reports/statement-of-account/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.loan.rate_label', '18.0% per year');
    }

    public function test_the_audit_trail_reads_the_annual_period_in_words(): void
    {
        $row = AuditLog::create([
            'action' => 'updated',
            'auditable_type' => LoanProduct::class,
            'auditable_id' => 1,
            'old_values' => ['interest_rate_frequency' => 'monthly'],
            'new_values' => ['interest_rate_frequency' => 'annual'],
        ]);

        $changes = collect($this->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes'))->keyBy('field');

        $this->assertSame('Monthly', $changes['interest_rate_frequency']['old']);
        $this->assertSame('Annual', $changes['interest_rate_frequency']['new']);
    }

    /**
     * Every row that differs differs only in its interest (and so its totals)
     * by ₱0.01, and the exact interest, the base × the monthly rate × the
     * row's days ÷ 30, is exactly a half-centavo, which the annual row rounds
     * up. The base is the balance before the row on a diminishing schedule,
     * else the principal.
     *
     * @param  array<string, mixed>  $input
     * @param  list<array<string, mixed>>  $monthlyRows
     * @param  list<array<string, mixed>>  $annualRows
     */
    private function assertOnlyHalfCentavoTies(array $input, string $monthlyRate, array $monthlyRows, array $annualRows, string $case): void
    {
        $instalments = LoanTermSchedule::instalments(Carbon::parse($input['start_date']), (int) $input['term'], $input['term_unit'], $input['frequency']);

        foreach ($monthlyRows as $i => $monthlyRow) {
            $annualRow = $annualRows[$i];

            if ($monthlyRow === $annualRow) {
                continue;
            }

            $this->assertSame('diminishing', $input['interest_method'], $case);
            $this->assertSame(array_diff_key($monthlyRow, array_flip(['interest_due', 'total_due', 'total_payment'])), array_diff_key($annualRow, array_flip(['interest_due', 'total_due', 'total_payment'])), $case);

            $base = $i === 0 ? (string) $input['principal_amount'] : (string) $monthlyRows[$i - 1]['remaining_balance'];
            $exact = BigRational::of($base)->multipliedBy($monthlyRate)->multipliedBy($instalments[$i]['days'])->dividedBy(3000);
            $centavos = $exact->multipliedBy(100);

            $this->assertTrue($centavos->minus($centavos->getIntegralPart())->isEqualTo(BigRational::ofFraction(1, 2)), "{$case}: row {$i} is not a half-centavo tie");
            $this->assertSame($exact->toScale(2, RoundingMode::HalfUp)->toFloat(), (float) $annualRow['interest_due'], $case);
            $this->assertEqualsWithDelta(0.01, (float) $annualRow['interest_due'] - (float) $monthlyRow['interest_due'], 0.000001, $case);
        }
    }

    /** @return array<string, mixed> */
    private function productPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Annual Rate Product',
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 12,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 0,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function calculatorInput(array $overrides = []): array
    {
        return array_merge([
            'principal_amount' => 50000,
            'interest_rate' => 3,
            'interest_rate_frequency' => 'monthly',
            'interest_method' => 'straight',
            'term' => 6,
            'term_unit' => 'months',
            'frequency' => 'monthly',
            'start_date' => '2026-01-31',
        ], $overrides);
    }

    /** @param  array<string, mixed>  $product */
    private function draftLoan(array $product): Loan
    {
        $product = LoanProduct::factory()->create(array_merge([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 12,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 0,
            'processing_fee' => 0,
            'service_fee' => 0,
            'notarial_fee' => 0,
            'min_amount' => 0,
            'max_amount' => 0,
        ], $product));

        return app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 50000,
            'start_date' => '2026-01-15',
            'deductions' => [],
        ], $this->admin)->fresh();
    }

    /** @param  array<string, mixed>  $product */
    private function approvedLoan(array $product): Loan
    {
        $loan = $this->draftLoan($product);
        $service = app(LoanService::class);
        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');

        return $loan->fresh();
    }
}
