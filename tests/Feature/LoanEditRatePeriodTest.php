<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The ordinary loan edit (PUT /loans/{loan}) holds a changed rate to its
 * product's range in the loan's own rate period, by the rule the Chair's edit
 * during approval uses (finish run round 4).
 *
 * A loan keeps the period its rate was quoted per when it was made. Once its
 * product quotes rates per another period, a changed rate would be stored per
 * the loan's period but checked against a range quoted per the product's, so
 * it is refused in words; an edit that leaves the rate alone still saves.
 * Creating a loan keeps its own check.
 */
class LoanEditRatePeriodTest extends TestCase
{
    use SetupLendyPH;

    private const PERIOD_MOVED = "This loan's rate is quoted per month, but its product now quotes rates per year, so the rate can't be changed.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_once_its_product_quotes_rates_per_year_a_changed_rate_is_refused_in_words(): void
    {
        $loan = $this->draftLoan();
        $this->productNowQuotesPerYear($loan);

        // 30 sits inside the product's 18% to 36%, but those are per year:
        // stored on this loan it would charge 30% a month.
        foreach ([30, 2.75] as $rate) {
            $this->putJson("/api/loans/{$loan->id}", ['interest_rate' => $rate, 'purpose' => 'Edited'])
                ->assertUnprocessable()
                ->assertJsonPath('errors.interest_rate', [self::PERIOD_MOVED]);
        }

        $loan->refresh();
        $this->assertSame('2.5000', $loan->interest_rate);
        $this->assertSame('monthly', $loan->interest_rate_frequency->value);
        $this->assertNotSame('Edited', $loan->purpose);
    }

    public function test_once_its_product_quotes_rates_per_year_an_edit_that_keeps_the_rate_saves(): void
    {
        $loan = $this->draftLoan();
        $this->productNowQuotesPerYear($loan);

        $this->putJson("/api/loans/{$loan->id}", ['purpose' => 'Working capital'])->assertOk();

        // The unchanged rate resent the way a full edit form sends it.
        $this->putJson("/api/loans/{$loan->id}", ['interest_rate' => '2.5000', 'principal_amount' => 50000])
            ->assertOk()
            ->assertJsonPath('data.interest_rate', '2.5000')
            ->assertJsonPath('data.purpose', 'Working capital');
        $this->putJson("/api/loans/{$loan->id}", ['interest_rate' => 2.5])->assertOk();

        $this->assertSame('50000.00', $loan->fresh()->getRawOriginal('principal_amount'));
    }

    public function test_a_same_period_edit_still_checks_the_range_and_names_the_period(): void
    {
        $loan = $this->draftLoan();

        foreach ([1.25, 1.4999, 3.0001, 3.5] as $rate) {
            $this->putJson("/api/loans/{$loan->id}", ['interest_rate' => $rate])
                ->assertUnprocessable()
                ->assertJsonPath('errors.interest_rate', ['The interest rate must be between 1.5% and 3% per month for this product.']);
        }

        $this->assertSame('2.5000', $loan->fresh()->interest_rate);

        $this->putJson("/api/loans/{$loan->id}", ['interest_rate' => 3])->assertOk()->assertJsonPath('data.interest_rate', '3.0000');
        $this->putJson("/api/loans/{$loan->id}", ['interest_rate' => 1.5])->assertOk()->assertJsonPath('data.interest_rate', '1.5000');
    }

    public function test_a_loan_quoted_per_year_is_checked_per_year(): void
    {
        $loan = $this->draftLoan($this->product(['interest_rate_frequency' => 'annual', 'interest_rate' => 36, 'min_interest_rate' => 18]), 24);

        $this->putJson("/api/loans/{$loan->id}", ['interest_rate' => 40])
            ->assertUnprocessable()
            ->assertJsonPath('errors.interest_rate', ['The interest rate must be between 18% and 36% per year for this product.']);

        $this->putJson("/api/loans/{$loan->id}", ['interest_rate' => 30])->assertOk()->assertJsonPath('data.interest_rate', '30.0000');
    }

    public function test_creating_a_loan_keeps_its_own_range_check(): void
    {
        $this->postJson('/api/loans', $this->payload($this->product(), ['interest_rate' => 3.5]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.interest_rate', ['Interest rate must be between 1.5% and 3% for this product.']);

        $this->assertSame(0, Loan::count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function product(array $overrides = []): LoanProduct
    {
        return LoanProduct::factory()->create([
            'interest_rate' => 3,
            'min_interest_rate' => 1.5,
            'interest_rate_frequency' => 'monthly',
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'processing_fee' => 0,
            'service_fee' => 0,
            'min_amount' => 0,
            'max_amount' => 0,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(LoanProduct $product, array $overrides = []): array
    {
        return [
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => '2026-12-01',
            ...$overrides,
        ];
    }

    private function draftLoan(?LoanProduct $product = null, float $rate = 2.5): Loan
    {
        return app(LoanService::class)->createLoan($this->payload($product ?? $this->product(), ['interest_rate' => $rate]), $this->admin);
    }

    /** Since the loan was made, its product quotes rates per year: 18% to 36%. */
    private function productNowQuotesPerYear(Loan $loan): void
    {
        $loan->loanProduct->update(['interest_rate_frequency' => 'annual', 'interest_rate' => 36, 'min_interest_rate' => 18]);
    }
}
