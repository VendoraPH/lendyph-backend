<?php

namespace Tests\Feature\IntegrityRound;

use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Item 8: the server decides a pledge's `snapshot_value`.
 *
 * Today POST /loans/{id}/collaterals, PUT /loans/{id} (`collaterals`) and
 * POST /loans/preview store or add up whatever `snapshot_value` the browser
 * sends (0 to 99,999,999.99), so a ₱250,000 collateral can be pledged at ₱1,
 * or a ₱1 one at ₱99M, and the loan's security status and "Short by" follow
 * the typed figure.
 *
 * Pinned (plan decision 1, option a): the value is the collateral's value as
 * CollateralValuation states it (its `amount`, or the member's share capital
 * balance), read under the attach's collateral row lock. A sent
 * `snapshot_value` is optional and ignored.
 */
class CollateralSnapshotValueTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function draftLoan(): Loan
    {
        return app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => LoanProduct::factory()->create()->id,
            'principal_amount' => 100000,
            'start_date' => now()->toDateString(),
        ], $this->admin);
    }

    private function pledgedValue(Loan $loan, Collateral $collateral): float
    {
        return (float) DB::table('loan_collaterals')
            ->where('loan_id', $loan->id)
            ->where('collateral_id', $collateral->id)
            ->value('snapshot_value');
    }

    public function test_attach_stores_the_collateral_value_not_the_sent_one(): void
    {
        $loan = $this->draftLoan();
        $collateral = Collateral::factory()->create(['borrower_id' => $loan->borrower_id, 'amount' => 250000]);

        $this->postJson("/api/loans/{$loan->id}/collaterals", [
            'collateral_id' => $collateral->id,
            'snapshot_value' => 1,
        ])->assertCreated();

        $this->assertEqualsWithDelta(250000.00, $this->pledgedValue($loan, $collateral), 0.001);
    }

    public function test_attach_needs_no_snapshot_value(): void
    {
        $loan = $this->draftLoan();
        $collateral = Collateral::factory()->create(['borrower_id' => $loan->borrower_id, 'amount' => 40000]);

        $this->postJson("/api/loans/{$loan->id}/collaterals", [
            'collateral_id' => $collateral->id,
        ])->assertCreated();

        $this->assertEqualsWithDelta(40000.00, $this->pledgedValue($loan, $collateral), 0.001);
    }

    public function test_a_loan_edit_pledges_new_collateral_at_its_value(): void
    {
        $loan = $this->draftLoan();
        $collateral = Collateral::factory()->create(['borrower_id' => $loan->borrower_id, 'amount' => 30000]);

        $this->putJson("/api/loans/{$loan->id}", [
            'collaterals' => [['collateral_id' => $collateral->id, 'snapshot_value' => 99999999]],
        ])->assertSuccessful();

        $this->assertEqualsWithDelta(30000.00, $this->pledgedValue($loan, $collateral), 0.001);
    }

    public function test_the_form_preview_values_collateral_by_its_id(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $collateral = Collateral::factory()->create(['borrower_id' => $borrower->id, 'amount' => 80000]);

        $preview = $this->postJson('/api/loans/preview', [
            'borrower_id' => $borrower->id,
            'principal_amount' => 100000,
            'collaterals' => [['collateral_id' => $collateral->id, 'snapshot_value' => 100000]],
        ])->assertOk()->json('data.collateral');

        $this->assertEquals(80000, $preview['total_value']);
        $this->assertSame('partially_secured', $preview['security_status']);
        $this->assertEquals(20000, $preview['short_by']);
    }
}
