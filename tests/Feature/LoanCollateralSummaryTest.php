<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /loans/{id}'s `collateral_summary`: the collaterals card's total, the
 * security status and the shortfall, by the rule and the code the loan form's
 * preview uses, so the card never adds the pledges up itself.
 */
class LoanCollateralSummaryTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_it_totals_the_pledged_values_against_the_principal(): void
    {
        $loan = $this->draft(60000, [30000.50, 19999.49]);

        $this->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.collateral_summary', [
                'total_value' => 49999.99,
                'security_status' => 'partially_secured',
                'short_by' => 10000.01,
            ]);
    }

    public function test_it_reads_secured_and_unsecured_loans(): void
    {
        $secured = $this->draft(60000, [40000, 20000]);
        $unsecured = $this->draft(60000, []);

        $this->assertEquals(
            ['total_value' => 60000, 'security_status' => 'secured', 'short_by' => 0],
            $this->getJson("/api/loans/{$secured->id}")->assertOk()->json('data.collateral_summary'),
        );
        $this->assertEquals(
            ['total_value' => 0, 'security_status' => 'unsecured', 'short_by' => 60000],
            $this->getJson("/api/loans/{$unsecured->id}")->assertOk()->json('data.collateral_summary'),
        );
    }

    public function test_it_is_the_figure_the_form_preview_gives_for_the_same_pledges(): void
    {
        $loan = $this->draft(60000, [12345.67, 0.01]);

        $card = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data.collateral_summary');
        $preview = $this->postJson('/api/loans/preview', [
            'principal_amount' => 60000,
            'collaterals' => [['snapshot_value' => 12345.67], ['snapshot_value' => 0.01]],
        ])->assertOk()->json('data.collateral');

        $this->assertSame($preview, $card);
    }

    public function test_the_loans_list_leaves_it_out_and_reads_no_collateral_for_it(): void
    {
        $this->draft(60000, [1000]);
        $this->draft(60000, [2000]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $this->getJson('/api/loans')->assertOk()->json('data');
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row['collateral_summary']);
        }
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('collaterals`', $sql);
        }
    }

    /**
     * A draft for `$principal` holding one collateral per value, pledged at it.
     *
     * @param  list<float|int>  $values
     */
    private function draft(float $principal, array $values): Loan
    {
        $loan = $this->freeDraft($principal);

        foreach ($values as $value) {
            $collateral = Collateral::factory()->create(['borrower_id' => $loan->borrower_id, 'amount' => 999999]);
            $loan->collaterals()->attach($collateral->id, ['snapshot_value' => $value, 'attached_at' => now()]);
        }

        return $loan;
    }

    private function freeDraft(float $principal): Loan
    {
        $product = LoanProduct::factory()->create(['processing_fee' => 0, 'service_fee' => 0, 'min_amount' => 0]);
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        return app(LoanService::class)->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
        ], $this->admin)->fresh();
    }
}
