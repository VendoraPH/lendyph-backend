<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Editing a loan's principal keeps it inside its product's minimum and
 * maximum amount, as creating the loan does, with the same 422s on
 * `principal_amount`.
 *
 * Only when the edit changes the principal, so a draft whose product range
 * narrowed after it was created stays editable. A restructure keeps its
 * exemption from the minimum (a part-paid balance is routinely below it) but
 * not from the maximum.
 */
class LoanEditAmountRangeTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_an_edit_below_the_product_minimum_is_refused(): void
    {
        $loan = $this->draftLoan(60000);

        $this->patchJson("/api/loans/{$loan->id}", ['principal_amount' => 5000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['principal_amount' => 'Minimum loan amount for this product is 10000.00.']);

        $this->assertSame('60000.00', $loan->fresh()->principal_amount);
    }

    public function test_an_edit_above_the_product_maximum_is_refused(): void
    {
        $loan = $this->draftLoan(60000);

        $this->patchJson("/api/loans/{$loan->id}", ['principal_amount' => 200000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['principal_amount' => 'Maximum loan amount for this product is 100000.00.']);

        $this->assertSame('60000.00', $loan->fresh()->principal_amount);
    }

    public function test_an_edit_inside_the_range_is_saved(): void
    {
        $loan = $this->draftLoan(60000);

        $this->patchJson("/api/loans/{$loan->id}", ['principal_amount' => 100000])->assertOk();

        $this->assertSame('100000.00', $loan->fresh()->principal_amount);
    }

    public function test_an_edit_that_leaves_the_principal_alone_is_not_rechecked_against_a_narrowed_range(): void
    {
        $loan = $this->draftLoan(60000);
        $loan->loanProduct->update(['min_amount' => 70000, 'max_amount' => 90000]);

        $this->patchJson("/api/loans/{$loan->id}", ['purpose' => 'School fees'])->assertOk();
        $this->patchJson("/api/loans/{$loan->id}", ['principal_amount' => 60000, 'purpose' => 'Tuition'])->assertOk();

        $this->assertSame('Tuition', $loan->fresh()->purpose);
    }

    public function test_a_restructure_may_still_be_edited_below_the_product_minimum(): void
    {
        $restructure = $this->restructureOf(['min_amount' => 50000, 'max_amount' => 100000], 60000);

        $this->patchJson("/api/loans/{$restructure->id}", [
            'principal_amount' => 20000,
            'remarks' => 'Borrower can only service a smaller balance',
        ])->assertOk();

        $this->assertSame('20000.00', $restructure->fresh()->principal_amount);
    }

    public function test_a_restructure_may_not_be_edited_above_the_product_maximum(): void
    {
        // ₱70,800 is owed on the source, so ₱65,000 passes the restructure's
        // own rules and only the product's ₱60,000 maximum refuses it.
        $restructure = $this->restructureOf(['min_amount' => 10000, 'max_amount' => 60000], 50000);

        $this->patchJson("/api/loans/{$restructure->id}", [
            'principal_amount' => 65000,
            'remarks' => 'Shortfall written off',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['principal_amount' => 'Maximum loan amount for this product is 60000.00.']);

        $this->assertSame('50000.00', $restructure->fresh()->principal_amount);
    }

    private function draftLoan(float $principal): Loan
    {
        $product = LoanProduct::factory()->create([
            'min_amount' => 10000,
            'max_amount' => 100000,
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
        ]);

        return app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
        ], $this->admin);
    }

    /**
     * A draft restructure of a released ₱60,000 loan (₱70,800 owed), on a
     * product with `$range`.
     *
     * @param  array{min_amount: int, max_amount: int}  $range
     */
    private function restructureOf(array $range, float $principal): Loan
    {
        $source = $this->createReleasedLoan(['product' => ['min_amount' => 0, 'max_amount' => 0]]);
        $source->loanProduct->update($range);

        $id = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
            'remarks' => 'Shortfall written off',
        ])->assertCreated()->json('data.id');

        return Loan::findOrFail($id);
    }
}
