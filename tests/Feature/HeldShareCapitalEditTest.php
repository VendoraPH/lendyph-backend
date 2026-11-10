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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A share capital pledge the edited loan already holds doesn't block editing
 * its other collateral (browser re-check, follow-up round 3).
 *
 * The edit form sends the loan's complete list, held share capital row
 * included. A caller without `share_capital:view` may list that held row: the
 * preview answers with the collateral figures withheld (the loan card's
 * shape) and every other section computed, and the save keeps the pledge
 * unchanged. Its value never appears in either response. Listing a share
 * capital collateral the loan doesn't hold yet still needs the permission on
 * the preview (422).
 *
 * The loan: ₱100,000.00, pledged with the member's share capital (balance
 * ₱4,321.87), and a land title of ₱20,000.00 added on edit.
 */
class HeldShareCapitalEditTest extends TestCase
{
    use SetupLendyPH;

    private Loan $loan;

    private Collateral $shareCapital;

    private Collateral $land;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->loan = app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => LoanProduct::factory()->create([
                'interest_rate' => 3.0,
                'interest_method' => 'straight',
                'term' => 6,
                'frequency' => 'monthly',
            ])->id,
            'principal_amount' => 100000,
            'start_date' => now()->toDateString(),
        ], $this->admin);

        ShareCapitalLedger::factory()->create(['borrower_id' => $this->loan->borrower_id, 'credit' => 4321.87, 'debit' => 0]);
        $this->shareCapital = $this->shareCapitalCollateral();
        $this->postJson("/api/loans/{$this->loan->id}/collaterals", ['collateral_id' => $this->shareCapital->id])->assertCreated();

        $this->land = Collateral::factory()->create(['borrower_id' => $this->loan->borrower_id, 'amount' => 20000]);
    }

    private function shareCapitalCollateral(): Collateral
    {
        return Collateral::factory()->create([
            'borrower_id' => $this->loan->borrower_id,
            'collateral_type_id' => CollateralType::where('source', 'share_capital')->firstOrFail()->id,
            // Far from the balance, so a leak of either would show.
            'amount' => 999999,
        ]);
    }

    private function editor(string ...$extra): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['loans:update', 'collaterals:view', 'collaterals:update', ...$extra]);

        return $user;
    }

    /**
     * @param  list<Collateral>  $collaterals
     * @return array<string, mixed>
     */
    private function previewBody(array $collaterals): array
    {
        return [
            'loan_id' => $this->loan->id,
            'loan_product_id' => $this->loan->loan_product_id,
            'principal_amount' => 100000,
            'interest_rate' => 3.0,
            'term' => 6,
            'frequency' => 'monthly',
            'start_date' => now()->toDateString(),
            'collaterals' => array_map(fn (Collateral $c): array => ['collateral_id' => $c->id], $collaterals),
        ];
    }

    private function assertNoShareCapitalFigure(string $content): void
    {
        // The balance, the total with the land title, and the shortfall left.
        foreach (['4321.87', '4321', '24321.87', '24321', '75678.13', '75678', '999999'] as $figure) {
            $this->assertStringNotContainsString($figure, $content, "{$figure} must not appear");
        }
    }

    private function pledgedValue(Collateral $collateral): ?float
    {
        $value = DB::table('loan_collaterals')->where('loan_id', $this->loan->id)->where('collateral_id', $collateral->id)->value('snapshot_value');

        return $value === null ? null : (float) $value;
    }

    public function test_adding_a_land_title_beside_a_held_share_capital_pledge_previews_with_the_figures_withheld(): void
    {
        $full = $this->postJson('/api/loans/preview', $this->previewBody([$this->shareCapital, $this->land]))->assertOk()->json('data');
        $this->assertEquals(24321.87, $full['collateral']['total_value'], 'setup: the balance plus the land title');

        $this->actingAs($this->editor());
        $response = $this->postJson('/api/loans/preview', $this->previewBody([$this->shareCapital, $this->land]))->assertOk();

        $this->assertSame(
            ['total_value' => null, 'security_status' => null, 'short_by' => null, 'value_unknown' => true],
            $response->json('data.collateral'),
        );
        $this->assertNotNull($response->json('data.maturity_date'));
        $this->assertNotNull($response->json('data.deductions.net_proceeds'));
        $this->assertNotNull($response->json('data.amortization'));
        $this->assertSame(
            collect($full)->except('collateral')->all(),
            collect($response->json('data'))->except('collateral')->all(),
            'every other section is computed as for a caller who can see the balance',
        );
        $this->assertNoShareCapitalFigure($response->getContent());
    }

    public function test_saving_the_list_keeps_the_held_share_capital_pledge_unchanged(): void
    {
        $this->actingAs($this->editor());

        $response = $this->putJson("/api/loans/{$this->loan->id}", [
            'collaterals' => [['collateral_id' => $this->shareCapital->id], ['collateral_id' => $this->land->id]],
        ])->assertOk();

        $this->assertEqualsWithDelta(4321.87, $this->pledgedValue($this->shareCapital), 0.001, 'kept at the value it was pledged at');
        $this->assertEqualsWithDelta(20000.00, $this->pledgedValue($this->land), 0.001);
        $this->assertSame(2, DB::table('loan_collaterals')->where('loan_id', $this->loan->id)->count());
        $this->assertNoShareCapitalFigure($response->getContent());
    }

    public function test_listing_a_share_capital_collateral_the_loan_doesnt_hold_still_needs_the_permission(): void
    {
        $other = $this->shareCapitalCollateral();
        $this->actingAs($this->editor());

        $this->postJson('/api/loans/preview', $this->previewBody([$this->shareCapital, $other]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['collaterals.1.collateral_id' => 'Previewing a share capital collateral needs the Share Capital: View permission.'])
            ->assertJsonMissingValidationErrors(['collaterals.0.collateral_id']);
    }

    public function test_with_the_permission_the_preview_shows_the_full_figures(): void
    {
        $this->actingAs($this->editor('share_capital:view'));

        $this->assertEquals(
            ['total_value' => 24321.87, 'security_status' => 'partially_secured', 'short_by' => 75678.13],
            $this->postJson('/api/loans/preview', $this->previewBody([$this->shareCapital, $this->land]))->assertOk()->json('data.collateral'),
        );
    }
}
