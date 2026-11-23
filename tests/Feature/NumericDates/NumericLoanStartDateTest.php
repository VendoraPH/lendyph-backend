<?php

namespace Tests\Feature\NumericDates;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A loan's start date sent as a number (20261124) or as bare digits
 * ("20261124") passes Laravel's `date` rule, which reads it as 2026-11-24.
 * LoanService stores the raw value, and the model's date cast reads a numeric
 * value as a Unix timestamp, so the start becomes 1970-08-23 while the
 * maturity date (parsed by Carbon) stays in 2027. A start date is written
 * year-month-day on create, edit, restructure, the form preview and the
 * amortization calculator.
 */
class NumericLoanStartDateTest extends TestCase
{
    use SetupLendyPH;

    private LoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo(Carbon::parse('2026-11-24 09:00'));

        $this->product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
        ]);
    }

    /**
     * @return array<string, array{int|float|string}>
     */
    public static function numericStartDates(): array
    {
        return [
            'a whole number' => [20261124],
            'a decimal' => [20261124.0],
            'digits as text' => ['20261124'],
        ];
    }

    #[DataProvider('numericStartDates')]
    public function test_a_new_loan_with_a_numeric_start_date_is_refused_and_nothing_is_saved(int|float|string $startDate): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $before = Loan::count();

        $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $this->product->id,
            'principal_amount' => 60000,
            'start_date' => $startDate,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');

        $this->assertSame($before, Loan::count(), 'no loan is saved');
    }

    #[DataProvider('numericStartDates')]
    public function test_a_loan_edit_with_a_numeric_start_date_is_refused_and_the_date_is_unchanged(int|float|string $startDate): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $loan = app(LoanService::class)->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $this->product->id,
            'principal_amount' => 60000,
            'start_date' => '2026-12-01',
        ], $this->admin);

        $this->putJson("/api/loans/{$loan->id}", ['start_date' => $startDate])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');

        $fresh = $loan->fresh();
        $this->assertSame('2026-12-01', $fresh->start_date->toDateString(), 'the start date is unchanged');
        $this->assertSame($loan->maturity_date->toDateString(), $fresh->maturity_date->toDateString(), 'the maturity date is unchanged');
    }

    #[DataProvider('numericStartDates')]
    public function test_a_restructure_with_a_numeric_start_date_is_refused_and_nothing_is_saved(int|float|string $startDate): void
    {
        $source = $this->createReleasedLoan();
        $before = Loan::count();

        $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => 70800,
            'start_date' => $startDate,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');

        $this->assertSame($before, Loan::count(), 'no new loan is saved');
        $this->assertSame($source->status, $source->fresh()->status, 'the source loan is untouched');
    }

    #[DataProvider('numericStartDates')]
    public function test_the_loan_form_preview_refuses_a_numeric_start_date(int|float|string $startDate): void
    {
        $this->postJson('/api/loans/preview', [
            'loan_product_id' => $this->product->id,
            'principal_amount' => 60000,
            'term' => 6,
            'frequency' => 'monthly',
            'start_date' => $startDate,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
    }

    #[DataProvider('numericStartDates')]
    public function test_the_amortization_calculator_refuses_a_numeric_start_date(int|float|string $startDate): void
    {
        $this->postJson('/api/loans/amortization-calculator', [
            'principal_amount' => 50000,
            'interest_rate' => 3,
            'interest_rate_frequency' => 'monthly',
            'interest_method' => 'straight',
            'term' => 6,
            'term_unit' => 'months',
            'frequency' => 'monthly',
            'start_date' => $startDate,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
    }

    /** The written form keeps working: the fix must not refuse it. */
    public function test_a_year_month_day_start_date_is_still_accepted(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $this->product->id,
            'principal_amount' => 60000,
            'start_date' => '2026-11-24',
        ])->assertCreated();

        $this->assertSame('2026-11-24', Loan::latest('id')->first()->start_date->toDateString());
    }
}
