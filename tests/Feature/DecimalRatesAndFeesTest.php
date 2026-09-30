<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Fractional rates and fees are stored, returned and computed exactly.
 *
 * Every rate and fee column is a DECIMAL with four places — the insurance
 * premium percentage has two. A value within that scale saves and reloads
 * unchanged and is the value the deductions and schedule are computed from;
 * one with more places is a 422, never a figure MySQL silently rounds to fit.
 */
class DecimalRatesAndFeesTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function product(array $overrides = []): LoanProduct
    {
        return LoanProduct::factory()->create(array_merge([
            'interest_rate' => 2.5,
            'min_interest_rate' => 2.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'processing_fee' => 0,
            'service_fee' => 0,
            'notarial_fee' => 0,
            'min_amount' => 0,
            'max_amount' => 0,
        ], $overrides));
    }

    private function loanPayload(LoanProduct $product, array $overrides = []): array
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        return array_merge([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => '2026-01-15',
        ], $overrides);
    }

    private function productPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Fractional Product',
            'interest_rate' => 2.5,
            'min_interest_rate' => 1.25,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'processing_fee' => 1.75,
            'min_processing_fee' => 1.5,
            'max_processing_fee' => 2.1234,
            'service_fee' => 0.5,
            'min_service_fee' => 0.25,
            'max_service_fee' => 0.75,
            'notarial_fee' => 0.1234,
            'penalty_rate' => 2.75,
            'custom_fees' => [['name' => 'Handling', 'type' => 'percentage', 'value' => 0.3333]],
        ], $overrides);
    }

    private function draftLoan(): Loan
    {
        return app(LoanService::class)->createLoan($this->loanPayload($this->product()), $this->admin);
    }

    /** @return list<array<string, mixed>> */
    private function preview(int $loanId): array
    {
        return $this->getJson("/api/loans/{$loanId}/amortization-preview")->assertOk()->json('data');
    }

    // ── Loans ────────────────────────────────────────────────────────────

    public function test_a_loan_with_a_fractional_rate_and_percentage_fee_saves_reloads_and_computes_exactly(): void
    {
        $loanId = $this->postJson('/api/loans', $this->loanPayload($this->product(), [
            'interest_rate' => 2.5,
            'deductions' => [
                ['name' => 'Processing Fee', 'amount' => 1.75, 'type' => 'percentage'],
                ['name' => 'Documentary Stamp', 'amount' => 150.25, 'type' => 'fixed'],
            ],
        ]))->assertCreated()->json('data.id');

        $loan = $this->getJson("/api/loans/{$loanId}")->assertOk()->json('data');

        $this->assertSame('2.5000', $loan['interest_rate']);
        $this->assertSame('2.5000', Loan::findOrFail($loanId)->interest_rate);
        $this->assertEquals(1.75, $loan['deductions'][0]['original_value']);
        // 60,000 x 1.75%. A rate cut to 1.7 or 2 would charge 1,020 or 1,200.
        $this->assertEquals(1050, $loan['deductions'][0]['amount']);
        $this->assertEquals(150.25, $loan['deductions'][1]['amount']);
        $this->assertSame('1200.25', $loan['total_deductions']);
        $this->assertSame('58799.75', $loan['net_proceeds']);

        // Straight interest at 2.5% a month on 60,000 is 1,500 every month.
        $schedule = $this->preview($loanId);
        $this->assertCount(6, $schedule);
        foreach ($schedule as $row) {
            $this->assertEquals(1500, $row['interest_due']);
        }
    }

    public function test_a_loan_rate_and_percentage_fee_keep_all_four_decimal_places(): void
    {
        $loanId = $this->postJson('/api/loans', $this->loanPayload($this->product(['interest_rate' => 2.1234]), [
            'interest_rate' => 2.1234,
            'deductions' => [['name' => 'Processing Fee', 'amount' => 1.2345, 'type' => 'percentage']],
        ]))->assertCreated()->json('data.id');

        $loan = $this->getJson("/api/loans/{$loanId}")->assertOk()->json('data');

        $this->assertSame('2.1234', $loan['interest_rate']);
        $this->assertEquals(1.2345, $loan['deductions'][0]['original_value']);
        // 60,000 x 1.2345% = 740.70.
        $this->assertEquals(740.7, $loan['deductions'][0]['amount']);
        $this->assertSame('59259.30', $loan['net_proceeds']);

        // 60,000 x 2.1234% = 1,274.04 a month.
        foreach ($this->preview($loanId) as $row) {
            $this->assertEquals(1274.04, $row['interest_due']);
        }
    }

    public function test_editing_a_loan_keeps_a_fractional_rate_and_fee_exact(): void
    {
        $loan = $this->draftLoan();

        $this->putJson("/api/loans/{$loan->id}", [
            'interest_rate' => 2.0625,
            'deductions' => [['name' => 'Service Fee', 'amount' => 0.875, 'type' => 'percentage']],
        ])->assertOk();

        $loan->refresh();
        $this->assertSame('2.0625', $loan->interest_rate);
        $this->assertEquals(0.875, $loan->deductions[0]['original_value']);
        // 60,000 x 0.875% = 525.
        $this->assertEquals(525, $loan->deductions[0]['amount']);
    }

    public function test_whole_number_loan_rates_and_fees_are_unchanged(): void
    {
        $product = $this->product(['interest_rate' => 3, 'min_interest_rate' => null]);

        $loanId = $this->postJson('/api/loans', $this->loanPayload($product, [
            'interest_rate' => 3,
            'deductions' => [['name' => 'Processing Fee', 'amount' => 2, 'type' => 'percentage']],
        ]))->assertCreated()->json('data.id');

        $loan = $this->getJson("/api/loans/{$loanId}")->assertOk()->json('data');

        $this->assertSame('3.0000', $loan['interest_rate']);
        $this->assertEquals(2, $loan['deductions'][0]['original_value']);
        $this->assertEquals(1200, $loan['deductions'][0]['amount']);
        foreach ($this->preview($loanId) as $row) {
            $this->assertEquals(1800, $row['interest_due']);
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function overPreciseLoanFields(): array
    {
        return [
            'interest rate' => [['interest_rate' => 2.12345], 'interest_rate'],
            'percentage deduction' => [
                ['deductions' => [['name' => 'Processing Fee', 'amount' => 1.23456, 'type' => 'percentage']]],
                'deductions.0.amount',
            ],
        ];
    }

    #[DataProvider('overPreciseLoanFields')]
    public function test_creating_a_loan_refuses_a_rate_with_more_than_four_decimal_places(array $fields, string $error): void
    {
        $this->postJson('/api/loans', $this->loanPayload($this->product(), $fields))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$error => 'must have 0-4 decimal places']);

        $this->assertSame(0, Loan::count());
    }

    #[DataProvider('overPreciseLoanFields')]
    public function test_editing_a_loan_refuses_a_rate_with_more_than_four_decimal_places(array $fields, string $error): void
    {
        $loan = $this->draftLoan();

        $this->putJson("/api/loans/{$loan->id}", $fields)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$error => 'must have 0-4 decimal places']);

        $this->assertSame('2.5000', $loan->fresh()->interest_rate);
    }

    #[DataProvider('overPreciseLoanFields')]
    public function test_restructuring_refuses_a_rate_with_more_than_four_decimal_places(array $fields, string $error): void
    {
        $source = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$source->id}/restructure", array_merge([
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ], $fields))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$error => 'must have 0-4 decimal places']);
    }

    // ── Loan edits and the product's rate range ──────────────────────────

    public function test_editing_a_loan_rate_within_the_product_range_is_accepted(): void
    {
        $loan = $this->draftLoan();

        $this->putJson("/api/loans/{$loan->id}", ['interest_rate' => 2.0625])
            ->assertOk()
            ->assertJsonPath('data.interest_rate', '2.0625');
    }

    /**
     * @return array<string, array{float}>
     */
    public static function ratesOutsideTheProductRange(): array
    {
        return [
            'above the product rate' => [2.75],
            'below the product minimum' => [1.9999],
        ];
    }

    #[DataProvider('ratesOutsideTheProductRange')]
    public function test_editing_a_loan_rate_outside_the_product_range_is_refused(float $rate): void
    {
        $loan = $this->draftLoan();

        $this->putJson("/api/loans/{$loan->id}", ['interest_rate' => $rate, 'purpose' => 'Edited'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'interest_rate' => 'Interest rate must be between 2% and 2.5% for this product.',
            ]);

        $loan->refresh();
        $this->assertSame('2.5000', $loan->interest_rate);
        $this->assertNotSame('Edited', $loan->purpose);
    }

    public function test_editing_a_loan_whose_rate_is_left_alone_is_not_re_checked_against_a_narrowed_range(): void
    {
        $loan = $this->draftLoan();
        $loan->loanProduct->update(['interest_rate' => 2.0, 'min_interest_rate' => 1.5]);

        // Unrelated fields, and the unchanged rate resent the way a full edit
        // form sends it: both still save, though 2.5% is now out of range.
        $this->putJson("/api/loans/{$loan->id}", ['purpose' => 'Working capital'])->assertOk();
        $this->putJson("/api/loans/{$loan->id}", ['interest_rate' => 2.5, 'principal_amount' => 50000])
            ->assertOk()
            ->assertJsonPath('data.interest_rate', '2.5000')
            ->assertJsonPath('data.purpose', 'Working capital');

        // Actually moving the rate is checked against the range as it is now.
        $this->putJson("/api/loans/{$loan->id}", ['interest_rate' => 2.25])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'interest_rate' => 'Interest rate must be between 1.5% and 2% for this product.',
            ]);
    }

    public function test_moving_a_loan_to_another_product_checks_its_rate_against_that_product(): void
    {
        // UpdateLoanRequest does not accept `loan_product_id`, so no endpoint
        // moves a loan between products today; the service still refuses a
        // move that would leave the rate outside the new product's range.
        $loan = $this->draftLoan();
        $other = $this->product(['interest_rate' => 4.0, 'min_interest_rate' => 3.0]);

        try {
            app(LoanService::class)->updateLoan($loan, ['loan_product_id' => $other->id], $this->admin);
            $this->fail('A rate outside the new product\'s range was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['Interest rate must be between 3% and 4% for this product.'],
                $e->errors()['interest_rate'],
            );
        }

        app(LoanService::class)->updateLoan($loan, ['loan_product_id' => $other->id, 'interest_rate' => 3.5], $this->admin);
        $this->assertSame($other->id, $loan->fresh()->loan_product_id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function editMethods(): array
    {
        return ['PUT' => ['putJson'], 'PATCH' => ['patchJson']];
    }

    #[DataProvider('editMethods')]
    public function test_editing_a_loan_with_a_null_rate_is_refused_rather_than_written(string $method): void
    {
        $loan = $this->draftLoan();

        // `loans.interest_rate` is NOT NULL: a sent null must be a 422, never
        // a write MySQL rejects.
        $this->{$method}("/api/loans/{$loan->id}", ['interest_rate' => null, 'purpose' => 'Edited'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['interest_rate']);

        $loan->refresh();
        $this->assertSame('2.5000', $loan->interest_rate);
        $this->assertNotSame('Edited', $loan->purpose);

        // Leaving the key out is still an edit that keeps the rate.
        $this->{$method}("/api/loans/{$loan->id}", ['purpose' => 'Edited'])
            ->assertOk()
            ->assertJsonPath('data.interest_rate', '2.5000')
            ->assertJsonPath('data.purpose', 'Edited');
    }

    public function test_creating_or_restructuring_with_a_null_rate_takes_the_product_rate(): void
    {
        // createLoan() falls back to the product rate, so on these two a null
        // means "the product's rate", not an attempt to store null.
        $this->postJson('/api/loans', $this->loanPayload($this->product(), ['interest_rate' => null]))
            ->assertCreated()
            ->assertJsonPath('data.interest_rate', '2.5000');

        $source = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            // The whole obligation: 60,000 principal + 6 x 3% interest.
            'principal_amount' => 70800,
            'start_date' => now()->toDateString(),
            'interest_rate' => null,
        ])
            ->assertCreated()
            ->assertJsonPath('data.interest_rate', '3.0000');
    }

    // ── Adjustments and release ──────────────────────────────────────────

    public function test_a_restructure_adjustment_keeps_a_fractional_rate_and_refuses_a_fifth_place(): void
    {
        $loan = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'restructure',
            'new_values' => ['interest_rate' => 2.12345],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_values.interest_rate' => 'must have 0-4 decimal places']);

        $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'restructure',
            'new_values' => ['interest_rate' => 2.1234],
        ])
            ->assertCreated()
            ->assertJsonPath('data.new_values.interest_rate', 2.1234);
    }

    public function test_the_release_insurance_percentage_keeps_two_places_and_refuses_a_third(): void
    {
        $loanService = app(LoanService::class);
        $loan = $this->draftLoan();
        $loanService->submitForReview($loan);
        $loanService->approve($loan, $this->admin, 'OK');

        $payload = [
            'insurance_premium_amount' => 750,
            'insurance_payment_type' => 'full',
            'insurance_remaining_balance' => 0,
        ];

        $this->patchJson("/api/loans/{$loan->id}/release", $payload + ['insurance_premium_percentage' => 1.255])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['insurance_premium_percentage' => 'must have 0-2 decimal places']);

        $this->patchJson("/api/loans/{$loan->id}/release", $payload + ['insurance_premium_percentage' => 1.25])
            ->assertOk()
            ->assertJsonPath('data.insurance_premium_percentage', 1.25);

        $this->assertSame('1.25', $loan->fresh()->insurance_premium_pct);
    }

    // ── Loan products ────────────────────────────────────────────────────

    public function test_a_product_with_fractional_rates_and_fees_saves_and_reloads_exactly(): void
    {
        $id = $this->postJson('/api/loan-products', $this->productPayload())->assertCreated()->json('data.id');

        $this->getJson("/api/loan-products/{$id}")
            ->assertOk()
            ->assertJsonPath('data.interest_rate', '2.5000')
            ->assertJsonPath('data.max_interest_rate', '2.5000')
            ->assertJsonPath('data.min_interest_rate', '1.2500')
            ->assertJsonPath('data.processing_fee', '1.7500')
            ->assertJsonPath('data.min_processing_fee', '1.5000')
            ->assertJsonPath('data.max_processing_fee', '2.1234')
            ->assertJsonPath('data.service_fee', '0.5000')
            ->assertJsonPath('data.min_service_fee', '0.2500')
            ->assertJsonPath('data.max_service_fee', '0.7500')
            ->assertJsonPath('data.notarial_fee', '0.1234')
            ->assertJsonPath('data.penalty_rate', '2.7500')
            ->assertJsonPath('data.custom_fees.0.value', 0.3333);

        $this->putJson("/api/loan-products/{$id}", ['interest_rate' => 2.0625, 'processing_fee' => 1.9999])
            ->assertOk()
            ->assertJsonPath('data.interest_rate', '2.0625')
            ->assertJsonPath('data.processing_fee', '1.9999');

        $this->assertDatabaseHas('loan_products', ['id' => $id, 'interest_rate' => 2.0625, 'processing_fee' => 1.9999]);
    }

    public function test_a_loan_created_from_a_fractional_product_copies_its_rates_and_fees_exactly(): void
    {
        $product = $this->product([
            'interest_rate' => 2.5,
            'processing_fee' => 1.75,
            'service_fee' => 0.5,
            'penalty_rate' => 2.75,
        ]);

        $loan = app(LoanService::class)->createLoan($this->loanPayload($product), $this->admin)->fresh();

        $this->assertSame('2.5000', $loan->interest_rate);
        $this->assertSame('2.7500', $loan->penalty_rate);
        $this->assertEquals([1.75, 0.5], array_column($loan->deductions, 'original_value'));
        // 60,000 x 1.75% and 60,000 x 0.5%.
        $this->assertEquals([1050, 300], array_column($loan->deductions, 'amount'));
    }

    public function test_whole_number_product_rates_and_fees_are_unchanged(): void
    {
        $id = $this->postJson('/api/loan-products', $this->productPayload([
            'interest_rate' => 3,
            'min_interest_rate' => 2,
            'processing_fee' => 2,
            'min_processing_fee' => 1,
            'max_processing_fee' => 3,
            'service_fee' => 1,
            'min_service_fee' => 1,
            'max_service_fee' => 1,
            'notarial_fee' => 0,
            'penalty_rate' => 2,
            'custom_fees' => [['name' => 'Handling', 'type' => 'fixed', 'value' => 100]],
        ]))->assertCreated()->json('data.id');

        $this->getJson("/api/loan-products/{$id}")
            ->assertOk()
            ->assertJsonPath('data.interest_rate', '3.0000')
            ->assertJsonPath('data.min_interest_rate', '2.0000')
            ->assertJsonPath('data.processing_fee', '2.0000')
            ->assertJsonPath('data.max_processing_fee', '3.0000')
            ->assertJsonPath('data.service_fee', '1.0000')
            ->assertJsonPath('data.notarial_fee', '0.0000')
            ->assertJsonPath('data.penalty_rate', '2.0000')
            ->assertJsonPath('data.custom_fees.0.value', 100);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function productRateFields(): array
    {
        $fields = [
            'interest_rate', 'min_interest_rate',
            'processing_fee', 'min_processing_fee', 'max_processing_fee',
            'service_fee', 'min_service_fee', 'max_service_fee',
            'notarial_fee', 'penalty_rate', 'custom_fees.0.value',
        ];

        return array_combine($fields, array_map(fn (string $field): array => [$field], $fields));
    }

    #[DataProvider('productRateFields')]
    public function test_creating_a_product_refuses_a_rate_or_fee_with_more_than_four_decimal_places(string $field): void
    {
        $payload = $this->productPayload();
        data_set($payload, $field, 1.23456);

        $this->postJson('/api/loan-products', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => 'must have 0-4 decimal places']);

        $this->assertSame(0, LoanProduct::where('name', 'Fractional Product')->count());
    }

    #[DataProvider('productRateFields')]
    public function test_editing_a_product_refuses_a_rate_or_fee_with_more_than_four_decimal_places(string $field): void
    {
        $product = $this->product();
        $payload = ['custom_fees' => [['name' => 'Handling', 'type' => 'percentage', 'value' => 0.5]]];
        data_set($payload, $field, 1.23456);

        $this->putJson("/api/loan-products/{$product->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => 'must have 0-4 decimal places']);

        $this->assertSame('2.5000', $product->fresh()->interest_rate);
    }

    // ── Fee catalog ──────────────────────────────────────────────────────

    public function test_a_percentage_fee_value_keeps_four_decimal_places_and_refuses_a_fifth(): void
    {
        $this->postJson('/api/fees', ['name' => 'Service Charge', 'type' => 'percentage', 'value' => 1.23456])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['value' => 'must have 0-4 decimal places']);

        $id = $this->postJson('/api/fees', ['name' => 'Service Charge', 'type' => 'percentage', 'value' => 1.2345])
            ->assertCreated()
            ->assertJsonPath('data.value', 1.2345)
            ->json('data.id');

        $this->putJson("/api/fees/{$id}", ['value' => 0.06255])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['value' => 'must have 0-4 decimal places']);

        $this->putJson("/api/fees/{$id}", ['value' => 0.0625])
            ->assertOk()
            ->assertJsonPath('data.value', 0.0625);

        $this->assertSame('0.0625', Fee::findOrFail($id)->value);
    }

    public function test_a_fixed_fee_value_is_a_peso_amount_and_refuses_a_third_decimal_place(): void
    {
        $this->postJson('/api/fees', ['name' => 'Documentary Stamp', 'type' => 'fixed', 'value' => 10.123])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['value' => 'must have 0-2 decimal places']);

        $id = $this->postJson('/api/fees', ['name' => 'Documentary Stamp', 'type' => 'fixed', 'value' => 10.12])
            ->assertCreated()
            ->assertJsonPath('data.value', 10.12)
            ->json('data.id');

        // No `type` sent: the fee is still fixed, so still two places.
        $this->putJson("/api/fees/{$id}", ['value' => 12.345])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['value' => 'must have 0-2 decimal places']);

        $this->putJson("/api/fees/{$id}", ['value' => 12.35])->assertOk()->assertJsonPath('data.value', 12.35);

        // Switching to percentage makes the same value a rate, with four places.
        $this->putJson("/api/fees/{$id}", ['type' => 'percentage', 'value' => 1.2345])
            ->assertOk()
            ->assertJsonPath('data.value', 1.2345);
    }

    public function test_switching_a_fee_to_fixed_holds_its_value_to_two_places(): void
    {
        $fee = Fee::create(['name' => 'Handling', 'type' => 'percentage', 'value' => 1.2345]);

        $this->putJson("/api/fees/{$fee->id}", ['type' => 'fixed', 'value' => 10.123])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['value' => 'must have 0-2 decimal places']);

        // `value` not resent: the stored four-place rate would become a peso
        // amount no one can charge exactly, so it is refused too.
        $this->putJson("/api/fees/{$fee->id}", ['type' => 'fixed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['value' => 'The value field must have 0-2 decimal places.']);

        $this->assertSame('percentage', $fee->fresh()->type);

        $this->putJson("/api/fees/{$fee->id}", ['type' => 'fixed', 'value' => 150])
            ->assertOk()
            ->assertJsonPath('data.type', 'fixed');

        // A stored value that already fits two places switches on its own.
        $whole = Fee::create(['name' => 'Filing', 'type' => 'percentage', 'value' => 2.5]);
        $this->putJson("/api/fees/{$whole->id}", ['type' => 'fixed'])->assertOk()->assertJsonPath('data.value', 2.5);
    }

    // ── Reports ──────────────────────────────────────────────────────────

    public function test_the_portfolio_report_does_not_round_a_fractional_product_rate(): void
    {
        $this->createReleasedLoan(['product' => ['interest_rate' => 2.1234]]);

        $portfolio = $this->getJson('/api/reports/portfolio-by-product')->assertOk()->json('data');

        $this->assertEquals(2.1234, $portfolio['products'][0]['avg_interest_rate']);
        $this->assertEquals(2.1234, $portfolio['totals']['avg_interest_rate']);
    }
}
