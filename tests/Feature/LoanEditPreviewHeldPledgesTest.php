<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\CollateralType;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The edit page's preview (POST /loans/preview with `loan_id`) counts the
 * collateral PUT /loans/{id} would leave the loan with (follow-up round 3
 * review):
 *
 *  - no `collaterals` (absent or null): the save leaves the pledges
 *    untouched, so the loan's held pledges count at their stored value, as
 *    its collaterals card shows them;
 *  - `[]`: the save detaches every one, so nothing counts;
 *  - a list: the listed rows, held ones at their pledged value.
 *
 * With no list the held pledges' values reach the total, so a held share
 * capital pledge needs `share_capital:view`, as a listed one does.
 */
class LoanEditPreviewHeldPledgesTest extends TestCase
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

    private function pledge(Loan $loan, float $amount): Collateral
    {
        $collateral = Collateral::factory()->create(['borrower_id' => $loan->borrower_id, 'amount' => $amount]);
        $this->postJson("/api/loans/{$loan->id}/collaterals", ['collateral_id' => $collateral->id])->assertCreated();

        return $collateral;
    }

    /** @param  array<string, mixed>  $extra */
    private function previewTotal(Loan $loan, array $extra = []): mixed
    {
        return $this->postJson('/api/loans/preview', ['loan_id' => $loan->id, 'principal_amount' => 100000, ...$extra])
            ->assertOk()->json('data.collateral.total_value');
    }

    public function test_no_list_counts_the_held_pledges_at_their_stored_value(): void
    {
        $loan = $this->draftLoan();
        $first = $this->pledge($loan, 30000);
        $this->pledge($loan, 12500.5);
        // Re-valued after it was pledged: the pledge keeps 30,000.
        $this->putJson("/api/collaterals/{$first->id}", ['amount' => 45000])->assertOk();

        $card = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data.collateral_summary');
        $preview = $this->postJson('/api/loans/preview', ['loan_id' => $loan->id, 'principal_amount' => 100000])
            ->assertOk()->json('data.collateral');

        $this->assertEquals(42500.5, $preview['total_value']);
        $this->assertSame($card, $preview);
        $this->assertEquals(42500.5, $this->previewTotal($loan, ['collaterals' => null]), 'null reads as no list');
    }

    public function test_an_empty_list_counts_nothing(): void
    {
        $loan = $this->draftLoan();
        $this->pledge($loan, 30000);

        $this->assertEquals(0, $this->previewTotal($loan, ['collaterals' => []]));
    }

    public function test_a_list_counts_only_its_rows(): void
    {
        $loan = $this->draftLoan();
        $held = $this->pledge($loan, 30000);
        $this->pledge($loan, 10000);
        $added = Collateral::factory()->create(['borrower_id' => $loan->borrower_id, 'amount' => 20000]);

        $this->assertEquals(30000, $this->previewTotal($loan, ['collaterals' => [['collateral_id' => $held->id]]]));
        $this->assertEquals(50000, $this->previewTotal($loan, ['collaterals' => [['collateral_id' => $held->id], ['collateral_id' => $added->id]]]));
    }

    /**
     * With no list a held share capital pledge's value would reach the total,
     * so a caller without `share_capital:view` gets the collateral section as
     * the loan card withholds it: `value_unknown`, and no total, status or
     * shortfall. Never a refusal, so the edit page's preview still works, and
     * every other figure is the same as for a caller who can see it.
     */
    public function test_with_no_list_a_held_share_capital_pledge_is_withheld_from_a_caller_who_cannot_see_it(): void
    {
        $loan = $this->draftLoan();
        ShareCapitalLedger::factory()->create(['borrower_id' => $loan->borrower_id, 'credit' => 4321.87, 'debit' => 0]);
        $shareCapital = Collateral::factory()->create([
            'borrower_id' => $loan->borrower_id,
            'collateral_type_id' => CollateralType::where('source', 'share_capital')->firstOrFail()->id,
            'amount' => 999999,
        ]);
        $this->postJson("/api/loans/{$loan->id}/collaterals", ['collateral_id' => $shareCapital->id])->assertCreated();
        $this->pledge($loan, 2000);

        $body = [
            'loan_id' => $loan->id,
            'loan_product_id' => $loan->loan_product_id,
            'principal_amount' => 100000,
            'interest_rate' => 3.0,
            'term' => 6,
            'frequency' => 'monthly',
            'start_date' => now()->toDateString(),
        ];
        $full = $this->postJson('/api/loans/preview', $body)->assertOk()->json('data');
        $this->assertEquals(6321.87, $full['collateral']['total_value'], 'setup: the balance plus ₱2,000.00');

        $user = User::factory()->create();
        $user->givePermissionTo(['loans:update', 'collaterals:view']);
        $this->actingAs($user);

        $response = $this->postJson('/api/loans/preview', $body)->assertOk();

        $this->assertSame(
            ['total_value' => null, 'security_status' => null, 'short_by' => null, 'value_unknown' => true],
            $response->json('data.collateral'),
        );
        foreach (['4321.87', '4321', '6321.87', '6321', '93678.13', '93678'] as $figure) {
            $this->assertStringNotContainsString($figure, $response->getContent(), "{$figure} must not appear");
        }
        $this->assertNotNull($full['amortization']);
        $this->assertNotNull($full['deductions']);
        $this->assertSame(
            collect($full)->except('collateral')->all(),
            collect($response->json('data'))->except('collateral')->all(),
            'every other figure is unchanged',
        );

        // An empty list counts nothing, so it reveals nothing.
        $this->assertEquals(0, $this->previewTotal($loan, ['collaterals' => []]));

        $user->givePermissionTo('share_capital:view');
        $this->assertSame($full['collateral'], $this->postJson('/api/loans/preview', $body)->assertOk()->json('data.collateral'));
    }
}
