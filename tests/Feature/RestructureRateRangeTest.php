<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\LoanAdjustment;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * An in-place restructure's new interest rate (finish run round 5) is held to
 * the rule a loan edit is held to: at most 100%, as every rate is, and, when
 * it changes the loan's rate, inside the product's range in the loan's own
 * rate period (LoanRateRange), in the same words.
 *
 * It is checked when the restructure is requested, approved and applied,
 * against the product as it is at that moment, so a product changed in between
 * refuses it. A request approved before this rule is judged when it is
 * applied: refused in words, it stays approved and the loan is left as it was.
 */
class RestructureRateRangeTest extends TestCase
{
    use SetupLendyPH;

    private const OVER_100 = 'The interest rate field must not be greater than 100.';

    private const OUTSIDE = 'The interest rate must be between 1.5% and 3% per month for this product.';

    private const PERIOD_MOVED = "This loan's rate is quoted per month, but its product now quotes rates per year, so the rate can't be changed.";

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        // 3% a month, the product's rate, which goes as low as 1.5%.
        $this->loan = $this->createReleasedLoan(['product' => ['min_interest_rate' => 1.5]]);
    }

    public function test_a_new_rate_above_100_percent_is_refused(): void
    {
        foreach ([150, 100.0001] as $rate) {
            $this->assertRefused($this->restructure(['interest_rate' => $rate, 'term' => 4]), self::OVER_100);
        }

        $this->assertSame(0, LoanAdjustment::count());
    }

    public function test_a_rate_outside_the_products_range_is_refused_in_words(): void
    {
        foreach ([1.25, 1.4999, 3.0001, 3.5] as $rate) {
            $this->assertRefused($this->restructure(['interest_rate' => $rate, 'term' => 4]), self::OUTSIDE);
        }

        $this->assertSame(0, LoanAdjustment::count());
    }

    public function test_a_rate_inside_the_products_range_saves_and_is_applied(): void
    {
        $this->restructure(['interest_rate' => 1.5])->assertCreated();

        $id = $this->restructure(['interest_rate' => 2.25, 'term' => 4])
            ->assertCreated()
            ->assertJsonPath('data.new_values.interest_rate', 2.25)
            ->json('data.id');

        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk()->assertJsonPath('data.status', 'applied');

        $this->assertSame('2.2500', $this->loan->fresh()->interest_rate);
    }

    public function test_once_its_product_quotes_rates_per_year_a_changed_rate_is_refused_with_the_shared_sentence(): void
    {
        $this->productNowQuotesPerYear();

        // 30 sits inside the product's 18% to 36%, but those are per year:
        // stored on this loan it would charge 30% a month.
        foreach ([30, 2.5] as $rate) {
            $this->assertRefused($this->restructure(['interest_rate' => $rate, 'term' => 4]), self::PERIOD_MOVED);
        }

        $this->assertSame(0, LoanAdjustment::count());
    }

    public function test_a_restructure_that_keeps_the_rate_saves_whatever_its_product_quotes_now(): void
    {
        $this->productNowQuotesPerYear();

        $id = $this->restructure(['interest_rate' => '3.0000', 'term' => 4])->assertCreated()->json('data.id');
        $this->restructure(['term' => 5])->assertCreated();

        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        $this->assertSame('3.0000', $this->loan->fresh()->interest_rate);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function productChanges(): array
    {
        return [
            'its range narrowed' => [['min_interest_rate' => 2.5], 'The interest rate must be between 2.5% and 3% per month for this product.'],
            'it quotes rates per year' => [['interest_rate_frequency' => 'annual', 'interest_rate' => 36, 'min_interest_rate' => 18], self::PERIOD_MOVED],
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('productChanges')]
    public function test_a_product_changed_after_approval_refuses_the_apply_and_leaves_the_loan_as_it_was(array $change, string $refusal): void
    {
        $id = $this->restructure(['interest_rate' => 2, 'term' => 4])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $schedule = $this->scheduleRows();

        $this->loan->loanProduct->update($change);

        $this->assertRefused($this->patchJson("/api/loan-adjustments/{$id}/apply"), $refusal);

        $this->assertSame('approved', LoanAdjustment::findOrFail($id)->status);
        $this->assertSame('3.0000', $this->loan->fresh()->interest_rate);
        $this->assertSame($schedule, $this->scheduleRows());
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('productChanges')]
    public function test_a_product_changed_before_approval_refuses_the_approval_and_the_request_can_still_be_rejected(array $change, string $refusal): void
    {
        $id = $this->restructure(['interest_rate' => 2, 'term' => 4])->assertCreated()->json('data.id');

        $this->loan->loanProduct->update($change);

        $this->assertRefused($this->patchJson("/api/loan-adjustments/{$id}/approve"), $refusal);
        $this->assertSame('pending', LoanAdjustment::findOrFail($id)->status);

        $this->patchJson("/api/loan-adjustments/{$id}/reject", ['remarks' => 'Rate outside the product'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_a_request_approved_before_the_cap_is_judged_when_applied(): void
    {
        $schedule = $this->scheduleRows();
        $over = $this->storedRestructure(['interest_rate' => 150, 'term' => 4], 'approved');
        $outside = $this->storedRestructure(['interest_rate' => 5, 'term' => 4], 'approved');

        $this->assertRefused($this->patchJson("/api/loan-adjustments/{$over->id}/apply"), self::OVER_100);
        $this->assertRefused($this->patchJson("/api/loan-adjustments/{$outside->id}/apply"), self::OUTSIDE);

        $this->assertSame(['approved', 'approved'], [$over->fresh()->status, $outside->fresh()->status]);
        $this->assertSame('3.0000', $this->loan->fresh()->interest_rate);
        $this->assertSame($schedule, $this->scheduleRows());
    }

    public function test_a_pending_request_from_before_the_cap_cannot_be_approved(): void
    {
        $over = $this->storedRestructure(['interest_rate' => 150, 'term' => 4], 'pending');
        // Stored when the rate was only `nullable`: refused in words, not a 500.
        $garbled = $this->storedRestructure(['interest_rate' => 'three', 'term' => 4], 'pending');

        $this->assertRefused($this->patchJson("/api/loan-adjustments/{$over->id}/approve"), self::OVER_100);
        $this->assertRefused($this->patchJson("/api/loan-adjustments/{$garbled->id}/approve"), 'The interest rate field must be a number.');
        $this->assertSame(['pending', 'pending'], [$over->fresh()->status, $garbled->fresh()->status]);
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function restructure(array $newValues): TestResponse
    {
        return $this->postJson("/api/loans/{$this->loan->id}/adjustments", [
            'adjustment_type' => 'restructure',
            'new_values' => $newValues,
        ]);
    }

    /** Refused (422) on the rate, with exactly `$message`. */
    private function assertRefused(TestResponse $response, string $message): void
    {
        $response->assertUnprocessable();

        $this->assertSame([$message], $response->json('errors')['new_values.interest_rate'] ?? null, (string) $response->getContent());
    }

    /**
     * A restructure written straight to the table, as requests made before the
     * rule were stored.
     *
     * @param  array<string, mixed>  $newValues
     */
    private function storedRestructure(array $newValues, string $status): LoanAdjustment
    {
        return LoanAdjustment::create([
            'loan_id' => $this->loan->id,
            'adjustment_type' => 'restructure',
            'old_values' => [],
            'new_values' => $newValues,
            'status' => $status,
            'adjusted_by' => $this->admin->id,
            ...($status === 'approved' ? ['approved_by' => $this->admin->id, 'approved_at' => now()] : []),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function scheduleRows(): array
    {
        return $this->loan->amortizationSchedules()->orderBy('id')
            ->get(['id', 'period_number', 'due_date', 'principal_due', 'interest_due', 'total_due', 'status'])
            ->toArray();
    }

    /** Since the loan was made, its product quotes rates per year: 18% to 36%. */
    private function productNowQuotesPerYear(): void
    {
        $this->loan->loanProduct->update(['interest_rate_frequency' => 'annual', 'interest_rate' => 36, 'min_interest_rate' => 18]);
    }
}
