<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\LoanProduct;
use App\Services\LoanService;
use App\Services\LoanTermSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `term_unit` and `interest_rate_frequency`: stored, validated, and applied.
 *
 * `term` is a length in `term_unit`; the rate is quoted per
 * `interest_rate_frequency` and converted on a 30-day month; a term that does
 * not split evenly ends in a shorter, prorated final instalment.
 */
class LoanTermUnitRateFrequencyTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function productPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Term Unit Product',
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 12,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 0,
        ], $overrides);
    }

    /**
     * A draft loan on a fresh product, built through LoanService::createLoan().
     */
    private function draftLoan(array $product, array $loan = []): Loan
    {
        $product = LoanProduct::factory()->create(array_merge([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 0,
            'processing_fee' => 0,
            'service_fee' => 0,
            'notarial_fee' => 0,
            'min_amount' => 0,
            'max_amount' => 0,
        ], $product));

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        return app(LoanService::class)->createLoan(array_merge([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => '2026-01-15',
        ], $loan), $this->admin)->fresh();
    }

    /** @return list<array<string, mixed>> */
    private function preview(Loan $loan): array
    {
        return $this->getJson("/api/loans/{$loan->id}/amortization-preview")->assertOk()->json('data');
    }

    // ── Stored and returned ──────────────────────────────────────────────

    public function test_both_fields_save_on_create_and_update_and_come_back_on_show(): void
    {
        $id = $this->postJson('/api/loan-products', $this->productPayload([
            'term' => 90,
            'term_unit' => 'days',
            'interest_rate_frequency' => 'weekly',
            'frequency' => 'weekly',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.term_unit', 'days')
            ->assertJsonPath('data.interest_rate_frequency', 'weekly')
            ->json('data.id');

        $this->assertDatabaseHas('loan_products', [
            'id' => $id,
            'term_unit' => 'days',
            'interest_rate_frequency' => 'weekly',
        ]);

        $this->putJson("/api/loan-products/{$id}", [
            'term_unit' => 'months',
            'interest_rate_frequency' => 'semi_monthly',
        ])
            ->assertOk()
            ->assertJsonPath('data.term_unit', 'months')
            ->assertJsonPath('data.interest_rate_frequency', 'semi_monthly');

        $this->getJson("/api/loan-products/{$id}")
            ->assertOk()
            ->assertJsonPath('data.term_unit', 'months')
            ->assertJsonPath('data.interest_rate_frequency', 'semi_monthly');
    }

    public function test_invalid_values_are_rejected_on_create_and_update(): void
    {
        $this->postJson('/api/loan-products', $this->productPayload([
            'term_unit' => 'weeks',
            'interest_rate_frequency' => 'upon_maturity',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_unit', 'interest_rate_frequency']);

        $product = LoanProduct::factory()->create();

        $this->putJson("/api/loan-products/{$product->id}", [
            'term_unit' => null,
            'interest_rate_frequency' => 'yearly',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_unit', 'interest_rate_frequency']);
    }

    public function test_a_product_created_without_them_is_a_months_term_at_a_monthly_rate(): void
    {
        $this->postJson('/api/loan-products', $this->productPayload())
            ->assertCreated()
            ->assertJsonPath('data.term_unit', 'months')
            ->assertJsonPath('data.interest_rate_frequency', 'monthly');

        // A row written without the columns — every row that predates them.
        $id = DB::table('loan_products')->insertGetId([
            'name' => 'Legacy Row',
            'interest_rate' => 3,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('loan_products', [
            'id' => $id,
            'term_unit' => 'months',
            'interest_rate_frequency' => 'monthly',
        ]);
    }

    // ── Existing behaviour ───────────────────────────────────────────────

    public function test_a_monthly_loan_keeps_its_schedule_and_maturity_to_the_centavo(): void
    {
        $straight = $this->draftLoan(['interest_method' => 'straight']);

        $this->assertSame('months', $straight->term_unit->value);
        $this->assertSame('monthly', $straight->interest_rate_frequency->value);
        $this->assertSame('2026-07-15', $straight->maturity_date->toDateString());

        $rows = $this->preview($straight);
        $this->assertCount(6, $rows);
        $this->assertSame(
            ['2026-02-15', '2026-03-15', '2026-04-15', '2026-05-15', '2026-06-15', '2026-07-15'],
            array_column($rows, 'due_date'),
        );

        foreach ($rows as $row) {
            $this->assertEquals(10000, $row['principal_due']);
            $this->assertEquals(1800, $row['interest_due']);
        }

        $diminishing = $this->draftLoan(['interest_method' => 'diminishing']);
        $rows = $this->preview($diminishing);

        $this->assertEquals([11075.85, 11075.85, 11075.85, 11075.85, 11075.85, 11075.85], array_column($rows, 'total_due'));
        $this->assertEquals([1800, 1521.72, 1235.1, 939.88, 635.8, 322.6], array_column($rows, 'interest_due'));
    }

    public function test_editing_the_product_later_does_not_reprice_an_existing_loan(): void
    {
        $loan = $this->draftLoan([]);
        $before = $this->preview($loan);

        $loan->loanProduct->update(['term_unit' => 'days', 'interest_rate_frequency' => 'daily']);

        $loan->refresh();
        $this->assertSame('months', $loan->term_unit->value);
        $this->assertSame('monthly', $loan->interest_rate_frequency->value);
        $this->assertSame($before, $this->preview($loan));
    }

    // ── Days terms ───────────────────────────────────────────────────────

    public function test_a_30_day_daily_loan_is_thirty_one_day_instalments(): void
    {
        $loan = $this->draftLoan(
            ['term' => 30, 'term_unit' => 'days', 'frequency' => 'daily', 'interest_rate' => 0.5, 'interest_rate_frequency' => 'daily'],
            ['principal_amount' => 30000, 'term' => 30, 'frequency' => 'daily', 'interest_rate' => 0.5],
        );

        $this->assertSame('2026-02-14', $loan->maturity_date->toDateString());

        $rows = $this->preview($loan);
        $this->assertCount(30, $rows);
        $this->assertSame('2026-01-16', $rows[0]['due_date']);
        $this->assertSame('2026-02-14', $rows[29]['due_date']);
        $this->assertEquals(1000, $rows[0]['principal_due']);
        $this->assertEquals(150, $rows[0]['interest_due']);
        $this->assertEqualsWithDelta(4500, array_sum(array_column($rows, 'interest_due')), 0.001);
    }

    public function test_a_45_day_weekly_loan_ends_in_a_short_prorated_instalment(): void
    {
        $loan = $this->draftLoan(
            ['term' => 45, 'max_term' => 90, 'term_unit' => 'days', 'frequency' => 'weekly', 'interest_rate' => 1, 'interest_rate_frequency' => 'weekly'],
            ['principal_amount' => 70000, 'term' => 45, 'frequency' => 'weekly', 'interest_rate' => 1],
        );

        $this->assertSame('2026-03-01', $loan->maturity_date->toDateString());

        $rows = $this->preview($loan);
        $this->assertCount(7, $rows);
        $this->assertSame(
            ['2026-01-22', '2026-01-29', '2026-02-05', '2026-02-12', '2026-02-19', '2026-02-26', '2026-03-01'],
            array_column($rows, 'due_date'),
        );

        // Six full weeks at 1% of 70,000, then 3 days of a week: 700 × 3/7.
        $this->assertEquals([700, 700, 700, 700, 700, 700, 300], array_column($rows, 'interest_due'));
        $this->assertEquals(10000, $rows[6]['principal_due']);
    }

    public function test_a_45_day_weekly_diminishing_loan_prorates_the_last_instalment_on_its_balance(): void
    {
        $loan = $this->draftLoan(
            ['term' => 45, 'term_unit' => 'days', 'frequency' => 'weekly', 'interest_method' => 'diminishing', 'interest_rate' => 1, 'interest_rate_frequency' => 'weekly'],
            ['principal_amount' => 70000, 'term' => 45, 'frequency' => 'weekly', 'interest_rate' => 1],
        );

        $rows = $this->preview($loan);
        $this->assertCount(7, $rows);
        $this->assertEquals(10403.98, $rows[0]['total_due']);
        // 10,300.97 left × 1% × 3/7.
        $this->assertEquals(10300.97, $rows[6]['principal_due']);
        $this->assertEquals(44.15, $rows[6]['interest_due']);
        $this->assertEqualsWithDelta(70000, array_sum(array_column($rows, 'principal_due')), 0.001);
    }

    public function test_a_90_day_monthly_loan_is_three_30_day_instalments(): void
    {
        $loan = $this->draftLoan(
            ['term' => 90, 'term_unit' => 'days'],
            ['principal_amount' => 90000, 'term' => 90],
        );

        $this->assertSame('2026-04-15', $loan->maturity_date->toDateString());

        $rows = $this->preview($loan);
        $this->assertSame(['2026-02-14', '2026-03-16', '2026-04-15'], array_column($rows, 'due_date'));
        $this->assertEquals([2700, 2700, 2700], array_column($rows, 'interest_due'));
    }

    public function test_a_45_day_single_payment_loan_is_charged_for_45_days(): void
    {
        $loan = $this->draftLoan(
            ['term' => 45, 'term_unit' => 'days', 'frequency' => 'upon_maturity'],
            ['principal_amount' => 100000, 'term' => 45, 'frequency' => 'upon_maturity'],
        );

        $this->assertSame('2026-03-01', $loan->maturity_date->toDateString());

        $rows = $this->preview($loan);
        $this->assertCount(1, $rows);
        $this->assertSame('2026-03-01', $rows[0]['due_date']);
        // 3% a month for 45 days: 4.5%.
        $this->assertEquals(4500, $rows[0]['interest_due']);
        $this->assertEquals(104500, $rows[0]['total_due']);
    }

    public function test_the_term_range_message_names_the_products_unit(): void
    {
        $product = LoanProduct::factory()->create([
            'term' => 90, 'min_term' => 30, 'max_term' => 90, 'term_unit' => 'days',
            'frequency' => 'daily', 'min_amount' => 0, 'max_amount' => 0,
        ]);
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'term' => 120,
            'frequency' => 'daily',
            'interest_rate' => 3,
            'start_date' => '2026-01-15',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.term.0', 'Term must be between 30 and 90 days for this product.');
    }

    // ── Rate frequency ───────────────────────────────────────────────────

    /** @return array<string, array{string, float}> */
    public static function rateFrequencies(): array
    {
        // 1% of 60,000 per rate period, for one 30-day month.
        return [
            'daily' => ['daily', 18000.00],
            'weekly' => ['weekly', 2571.43],
            'bi_weekly' => ['bi_weekly', 1285.71],
            'semi_monthly' => ['semi_monthly', 1200.00],
            'monthly' => ['monthly', 600.00],
        ];
    }

    #[DataProvider('rateFrequencies')]
    public function test_each_rate_frequency_converts_to_a_monthly_instalment(string $rateFrequency, float $interest): void
    {
        $loan = $this->draftLoan(
            ['term' => 3, 'interest_rate' => 1, 'interest_rate_frequency' => $rateFrequency],
            ['term' => 3, 'interest_rate' => 1],
        );

        $this->assertSame($rateFrequency, $loan->interest_rate_frequency->value);

        $rows = $this->preview($loan);
        $this->assertCount(3, $rows);
        $this->assertEquals([$interest, $interest, $interest], array_column($rows, 'interest_due'));
    }

    // ── Loans API, printouts, extension, adjustments ─────────────────────

    public function test_the_loan_and_its_printout_payloads_carry_both_fields(): void
    {
        $loan = $this->createReleasedLoan([
            'product' => ['term' => 45, 'term_unit' => 'days', 'frequency' => 'weekly', 'interest_rate' => 1.0, 'interest_rate_frequency' => 'weekly'],
            'principal_amount' => 70000,
        ]);

        $this->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.term_unit', 'days')
            ->assertJsonPath('data.interest_rate_frequency', 'weekly');

        $this->getJson("/api/loans/{$loan->id}/disclosure")
            ->assertOk()
            ->assertJsonPath('data.loan_terms.term_unit', 'days')
            ->assertJsonPath('data.loan_terms.interest_rate_frequency', 'weekly');

        $this->getJson("/api/loans/{$loan->id}/promissory-note")
            ->assertOk()
            ->assertJsonPath('data.loan_terms.term_unit', 'days')
            ->assertJsonPath('data.loan_terms.interest_rate_frequency', 'weekly');
    }

    public function test_only_a_one_month_months_term_counts_as_one_month_for_extension(): void
    {
        $days = $this->draftLoan(
            ['term' => 30, 'term_unit' => 'days', 'frequency' => 'upon_maturity'],
            ['term' => 30, 'frequency' => 'upon_maturity'],
        );
        $months = $this->draftLoan(
            ['term' => 1, 'frequency' => 'upon_maturity'],
            ['term' => 1, 'frequency' => 'upon_maturity'],
        );

        $this->assertFalse($days->isOneMonthTerm());
        $this->assertTrue($months->isOneMonthTerm());
    }

    public function test_an_extension_charges_a_month_at_the_converted_rate(): void
    {
        $loan = $this->createReleasedLoan([
            'product' => [
                'interest_method' => 'upon_maturity',
                'term' => 1,
                'frequency' => 'monthly',
                'interest_rate' => 1.0,
                'interest_rate_frequency' => 'weekly',
            ],
            'principal_amount' => 70000,
            'start_date' => '2026-04-27',
        ]);

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        // One 30-day month at 1% a week: 70,000 × 1% × 30/7.
        $adjustment = LoanAdjustment::where('loan_id', $loan->id)->firstOrFail();
        $this->assertEquals(3000, $adjustment->new_values['fresh_interest']);
    }

    public function test_a_term_extension_on_a_days_loan_adds_whole_instalments_in_days(): void
    {
        $loan = $this->createReleasedLoan([
            'product' => [
                'term' => 42,
                'term_unit' => 'days',
                'frequency' => 'weekly',
                'interest_rate' => 1.0,
                'interest_rate_frequency' => 'weekly',
            ],
            'principal_amount' => 60000,
            'start_date' => '2026-01-15',
        ]);
        $this->assertCount(6, $loan->amortizationSchedules);

        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'term_extension',
            'new_values' => ['additional_terms' => 2],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        $loan->refresh()->load('amortizationSchedules');
        $this->assertSame('days', $loan->term_unit->value);
        $this->assertSame(56, $loan->term);
        $this->assertCount(8, $loan->amortizationSchedules);
        $this->assertSame('2026-03-12', $loan->maturity_date->toDateString());
    }

    public function test_a_days_single_payment_loan_cannot_be_rescheduled_by_instalment_count(): void
    {
        $loan = $this->createReleasedLoan([
            'product' => ['term' => 45, 'term_unit' => 'days', 'frequency' => 'upon_maturity'],
        ]);

        $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'term_extension',
            'new_values' => ['additional_terms' => 1],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('adjustment_type');
    }

    // ── The importer's period counts ─────────────────────────────────────

    public function test_an_imported_period_count_becomes_the_exact_term_that_reproduces_it(): void
    {
        $this->assertSame(
            ['term' => 6, 'term_unit' => 'months', 'interest_rate_frequency' => 'monthly'],
            LoanTermSchedule::fromPeriodCount(6, 'monthly'),
        );
        $this->assertSame(
            ['term' => 182, 'term_unit' => 'days', 'interest_rate_frequency' => 'weekly'],
            LoanTermSchedule::fromPeriodCount(26, 'weekly'),
        );

        // 26 weeks as a days term, at a rate per week: 26 instalments, each
        // charged the full rate, maturing where 26 weekly periods do.
        $service = new LoanService;
        $start = Carbon::parse('2025-01-15');
        $this->assertSame(
            $service->computeMaturityDate('2025-01-15', 26, 'weekly')->toDateString(),
            $service->maturityDateFor('2025-01-15', 182, 'days', 'weekly')->toDateString(),
        );
        $this->assertCount(26, LoanTermSchedule::instalments($start, 182, 'days', 'weekly'));
        $this->assertSame(0.03, LoanTermSchedule::rateForDays(3, 'weekly', 7));
    }
}
