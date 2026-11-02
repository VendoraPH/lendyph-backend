<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\CollateralType;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\CollateralSecurity;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The rules around the server-set pledge value (decision 11a), the restructure
 * preview (decision 8a) and the marker a restructure into a new loan leaves on
 * the source's closed periods (decision 18a), beyond what the pinned
 * CollateralSnapshotValueTest, RestructureShortfallPreviewTest and
 * RestructureClosedPeriodsTest cover.
 */
class CollateralServerValueTest extends TestCase
{
    use BuildsReleasedLoans, SetupLendyPH;

    private CollateralType $shareCapitalType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->shareCapitalType = CollateralType::where('source', 'share_capital')->firstOrFail();
    }

    private function draftLoan(?Borrower $borrower = null): Loan
    {
        return app(LoanService::class)->createLoan([
            'borrower_id' => ($borrower ?? Borrower::factory()->create(['branch_id' => $this->branch->id]))->id,
            'loan_product_id' => LoanProduct::factory()->create()->id,
            'principal_amount' => 100000,
            'start_date' => now()->toDateString(),
        ], $this->admin);
    }

    private function shareCapitalCollateral(Borrower|int $borrower, float $credit, float $debit = 0): Collateral
    {
        $borrowerId = $borrower instanceof Borrower ? $borrower->id : $borrower;
        ShareCapitalLedger::factory()->create(['borrower_id' => $borrowerId, 'credit' => $credit, 'debit' => $debit]);

        return Collateral::factory()->create([
            'borrower_id' => $borrowerId,
            'collateral_type_id' => $this->shareCapitalType->id,
            // Far from the balance, so a test can tell which one was used.
            'amount' => 999999,
        ]);
    }

    private function pledgedValue(Loan $loan, Collateral $collateral): float
    {
        return (float) DB::table('loan_collaterals')
            ->where('loan_id', $loan->id)
            ->where('collateral_id', $collateral->id)
            ->value('snapshot_value');
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    // ── the pledge value ─────────────────────────────────────────────────

    public function test_a_share_capital_pledge_is_the_members_balance(): void
    {
        $loan = $this->draftLoan();
        $collateral = $this->shareCapitalCollateral($loan->borrower_id, 5000, 1500);

        $this->postJson("/api/loans/{$loan->id}/collaterals", ['collateral_id' => $collateral->id])->assertCreated();

        $this->assertEqualsWithDelta(3500.00, $this->pledgedValue($loan, $collateral), 0.001);
    }

    public function test_a_negative_share_capital_balance_pledges_nothing(): void
    {
        $loan = $this->draftLoan();
        $collateral = $this->shareCapitalCollateral($loan->borrower_id, 1000, 1600);

        $this->postJson("/api/loans/{$loan->id}/collaterals", ['collateral_id' => $collateral->id])->assertCreated();

        $this->assertEqualsWithDelta(0.00, $this->pledgedValue($loan, $collateral), 0.001);
    }

    // ── who sees a share capital pledge's value ──────────────────────────

    public function test_a_caller_who_cannot_see_balances_does_not_see_a_share_capital_pledge(): void
    {
        $loan = $this->draftLoan();
        $collateral = $this->shareCapitalCollateral($loan->borrower_id, 7250.5);

        $this->actingAs($this->userWith('collaterals:update', 'loans:update', 'collaterals:view'));

        $attached = $this->postJson("/api/loans/{$loan->id}/collaterals", ['collateral_id' => $collateral->id])
            ->assertCreated()
            ->json('data');

        // Pledged at the real balance all the same; only the response withholds it.
        $this->assertEqualsWithDelta(7250.50, $this->pledgedValue($loan, $collateral), 0.001);
        $this->assertTrue($attached['value_unknown']);
        $this->assertArrayHasKey('snapshot_value', $attached['pivot']);
        $this->assertNull($attached['pivot']['snapshot_value']);

        $listed = $this->getJson("/api/loans/{$loan->id}/collaterals")->assertOk()->json('data.0');
        $this->assertSame($collateral->id, $listed['id']);
        $this->assertNull($listed['pivot']['snapshot_value']);
    }

    public function test_a_caller_who_can_see_balances_sees_a_share_capital_pledge(): void
    {
        $loan = $this->draftLoan();
        $collateral = $this->shareCapitalCollateral($loan->borrower_id, 7250.5);

        $this->actingAs($this->userWith('collaterals:update', 'loans:update', 'collaterals:view', 'share_capital:view'));

        $this->postJson("/api/loans/{$loan->id}/collaterals", ['collateral_id' => $collateral->id])
            ->assertCreated()
            ->assertJsonPath('data.value_unknown', false)
            ->assertJsonPath('data.pivot.snapshot_value', 7250.5);

        $this->getJson("/api/loans/{$loan->id}/collaterals")
            ->assertOk()
            ->assertJsonPath('data.0.pivot.snapshot_value', 7250.5);
    }

    public function test_a_caller_who_cannot_see_balances_still_sees_a_manual_pledge(): void
    {
        $loan = $this->draftLoan();
        $land = Collateral::factory()->create(['borrower_id' => $loan->borrower_id, 'amount' => 40000]);

        $this->actingAs($this->userWith('collaterals:update', 'loans:update', 'collaterals:view'));

        $this->postJson("/api/loans/{$loan->id}/collaterals", ['collateral_id' => $land->id])
            ->assertCreated()
            ->assertJsonPath('data.pivot.snapshot_value', 40000);
    }

    // ── the loan's collateral summary ────────────────────────────────────

    public function test_a_loan_summary_withholds_a_share_capital_pledge_from_a_caller_who_cannot_see_balances(): void
    {
        $loan = $this->draftLoan();
        $collateral = $this->shareCapitalCollateral($loan->borrower_id, 7250.5);
        $this->postJson("/api/loans/{$loan->id}/collaterals", ['collateral_id' => $collateral->id])->assertCreated();
        $loan->load('collaterals');

        $this->assertSame(
            ['total_value' => null, 'security_status' => 'partially_secured', 'short_by' => null, 'value_unknown' => true],
            CollateralSecurity::ofLoan($loan, $this->userWith('loans:view')),
        );
        $this->assertSame(
            ['total_value' => null, 'security_status' => 'partially_secured', 'short_by' => null, 'value_unknown' => true],
            CollateralSecurity::ofLoan($loan->fresh('collaterals')),
        );
        $this->assertEquals(
            ['total_value' => 7250.5, 'security_status' => 'partially_secured', 'short_by' => 92749.5],
            CollateralSecurity::ofLoan($loan->fresh('collaterals'), $this->userWith('loans:view', 'share_capital:view')),
        );
    }

    public function test_a_loan_summary_without_share_capital_is_shown_to_everyone(): void
    {
        $loan = $this->draftLoan();
        $land = Collateral::factory()->create(['borrower_id' => $loan->borrower_id, 'amount' => 40000]);
        $this->postJson("/api/loans/{$loan->id}/collaterals", ['collateral_id' => $land->id])->assertCreated();

        $this->assertEquals(
            ['total_value' => 40000, 'security_status' => 'partially_secured', 'short_by' => 60000],
            CollateralSecurity::ofLoan($loan->fresh('collaterals'), $this->userWith('loans:view')),
        );
    }

    // ── POST /loans/preview with loan_id (the edit page) ─────────────────

    public function test_an_edit_preview_carries_held_pledges_at_their_pledged_value(): void
    {
        $loan = $this->draftLoan();
        $held = Collateral::factory()->create(['borrower_id' => $loan->borrower_id, 'amount' => 30000]);
        $added = Collateral::factory()->create(['borrower_id' => $loan->borrower_id, 'amount' => 20000]);
        $this->postJson("/api/loans/{$loan->id}/collaterals", ['collateral_id' => $held->id])->assertCreated();
        // Re-valued after it was pledged: the pledge keeps 30,000.
        $this->putJson("/api/collaterals/{$held->id}", ['amount' => 45000])->assertOk();

        $card = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data.collateral_summary');
        $preview = $this->postJson('/api/loans/preview', [
            'loan_id' => $loan->id,
            'principal_amount' => 100000,
            'collaterals' => [['collateral_id' => $held->id]],
        ])->assertOk()->json('data.collateral');

        $this->assertEquals(30000, $card['total_value']);
        $this->assertSame($card, $preview);

        $this->postJson('/api/loans/preview', [
            'loan_id' => $loan->id,
            'principal_amount' => 100000,
            'collaterals' => [['collateral_id' => $held->id], ['collateral_id' => $added->id]],
        ])->assertOk()->assertJsonPath('data.collateral.total_value', 50000);
    }

    public function test_an_edit_preview_follows_the_edit_rules(): void
    {
        $loan = $this->draftLoan();
        $body = ['loan_id' => $loan->id, 'principal_amount' => 100000];

        $this->postJson('/api/loans/preview', [...$body, 'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['borrower_id']);

        $this->postJson('/api/loans/preview', [...$body, 'source_loan_id' => $loan->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['loan_id']);

        $this->actingAs($this->userWith('loans:create'));
        $this->postJson('/api/loans/preview', $body)->assertForbidden();

        $this->actingAs($this->userWith('loans:update'));
        $this->postJson('/api/loans/preview', $body)->assertOk();

        $loan->update(['status' => 'approved']);
        $this->postJson('/api/loans/preview', $body)->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    public function test_listing_collateral_in_a_preview_needs_collaterals_view(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $land = Collateral::factory()->create(['borrower_id' => $borrower->id, 'amount' => 50000]);
        $body = ['borrower_id' => $borrower->id, 'principal_amount' => 10000, 'collaterals' => [['collateral_id' => $land->id]]];

        $this->actingAs($this->userWith('loans:create'));
        $this->postJson('/api/loans/preview', $body)->assertUnprocessable()->assertJsonValidationErrors(['collaterals']);
        $this->postJson('/api/loans/preview', ['principal_amount' => 10000, 'collaterals' => []])->assertOk();

        $this->actingAs($this->userWith('loans:create', 'collaterals:view'));
        $this->postJson('/api/loans/preview', $body)->assertOk()->assertJsonPath('data.collateral.total_value', 50000);
    }

    // ── POST /loans/preview collateral ───────────────────────────────────

    public function test_the_preview_values_share_capital_at_the_balance(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $collateral = $this->shareCapitalCollateral($borrower, 12000);

        $preview = $this->postJson('/api/loans/preview', [
            'borrower_id' => $borrower->id,
            'principal_amount' => 10000,
            'collaterals' => [['collateral_id' => $collateral->id]],
        ])->assertOk()->json('data.collateral');

        $this->assertEquals(12000, $preview['total_value']);
        $this->assertSame('secured', $preview['security_status']);
    }

    public function test_a_caller_who_cannot_see_balances_cannot_preview_share_capital(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $shareCapital = $this->shareCapitalCollateral($borrower, 12000);
        $land = Collateral::factory()->create(['borrower_id' => $borrower->id, 'amount' => 50000]);

        $this->actingAs($this->userWith('loans:create', 'collaterals:view'));

        $this->postJson('/api/loans/preview', [
            'borrower_id' => $borrower->id,
            'principal_amount' => 10000,
            'collaterals' => [['collateral_id' => $land->id], ['collateral_id' => $shareCapital->id]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['collaterals.1.collateral_id'])
            ->assertJsonMissingValidationErrors(['collaterals.0.collateral_id']);

        $this->postJson('/api/loans/preview', [
            'borrower_id' => $borrower->id,
            'principal_amount' => 10000,
            'collaterals' => [['collateral_id' => $land->id]],
        ])->assertOk()->assertJsonPath('data.collateral.total_value', 50000);
    }

    public function test_the_preview_needs_a_borrower_only_once_collateral_is_listed(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $collateral = Collateral::factory()->create(['borrower_id' => $borrower->id, 'amount' => 50000]);

        $this->postJson('/api/loans/preview', [
            'principal_amount' => 10000,
            'collaterals' => [['collateral_id' => $collateral->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['borrower_id']);

        $this->postJson('/api/loans/preview', ['principal_amount' => 10000, 'collaterals' => []])
            ->assertOk()->assertJsonPath('data.collateral.total_value', 0);

        $this->postJson('/api/loans/preview', ['principal_amount' => 10000])->assertOk();
    }

    public function test_the_preview_refuses_another_borrowers_collateral(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $someoneElses = Collateral::factory()->create([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'amount' => 50000,
        ]);

        $this->postJson('/api/loans/preview', [
            'borrower_id' => $borrower->id,
            'principal_amount' => 10000,
            'collaterals' => [['collateral_id' => $someoneElses->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['collaterals.0.collateral_id']);
    }

    // ── POST /loans/preview with source_loan_id ──────────────────────────

    public function test_a_restructure_preview_carries_the_sources_pledge_value_and_its_borrower(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $source = $this->releasedLoanFor($borrower);
        $carried = Collateral::factory()->create(['borrower_id' => $borrower->id, 'amount' => 30000]);
        $added = Collateral::factory()->create(['borrower_id' => $borrower->id, 'amount' => 20000]);
        // Pledged at 25,000 when its amount was lower; the restructure copies that.
        $source->collaterals()->attach($carried->id, ['snapshot_value' => 25000, 'attached_at' => now()]);

        $preview = $this->postJson('/api/loans/preview', [
            'source_loan_id' => $source->id,
            'principal_amount' => 70800,
            'collaterals' => [['collateral_id' => $carried->id], ['collateral_id' => $added->id]],
        ])->assertOk()->json('data');

        $this->assertEquals(45000, $preview['collateral']['total_value']);
        $this->assertEqualsWithDelta(0.00, (float) $preview['restructure']['shortfall'], 0.001);

        $this->postJson('/api/loans/preview', [
            'source_loan_id' => $source->id,
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'principal_amount' => 70800,
        ])->assertUnprocessable()->assertJsonValidationErrors(['borrower_id']);
    }

    public function test_a_restructure_preview_counts_every_pledge_of_the_source_listed_or_not(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $source = $this->releasedLoanFor($borrower);
        $first = Collateral::factory()->create(['borrower_id' => $borrower->id, 'amount' => 30000]);
        $second = Collateral::factory()->create(['borrower_id' => $borrower->id, 'amount' => 10000]);
        $source->collaterals()->attach($first->id, ['snapshot_value' => 25000, 'attached_at' => now()]);
        $source->collaterals()->attach($second->id, ['snapshot_value' => 10000, 'attached_at' => now()]);
        $body = ['source_loan_id' => $source->id, 'principal_amount' => 70800];

        $this->postJson('/api/loans/preview', $body)->assertOk()->assertJsonPath('data.collateral.total_value', 35000);
        $this->postJson('/api/loans/preview', [...$body, 'collaterals' => [['collateral_id' => $first->id]]])
            ->assertOk()->assertJsonPath('data.collateral.total_value', 35000);
    }

    public function test_a_restructure_preview_of_a_loan_secured_by_share_capital_needs_share_capital_view(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $source = $this->releasedLoanFor($borrower);
        $shareCapital = $this->shareCapitalCollateral($borrower, 9000);
        $source->collaterals()->attach($shareCapital->id, ['snapshot_value' => 9000, 'attached_at' => now()]);
        $body = ['source_loan_id' => $source->id, 'principal_amount' => 70800];

        $this->actingAs($this->userWith('loans:restructure'));
        $this->postJson('/api/loans/preview', $body)->assertUnprocessable()->assertJsonValidationErrors(['source_loan_id']);

        $this->actingAs($this->userWith('loans:restructure', 'share_capital:view'));
        $this->postJson('/api/loans/preview', $body)->assertOk()->assertJsonPath('data.collateral.total_value', 9000);
    }

    public function test_a_restructure_preview_needs_the_restructure_permission(): void
    {
        $source = $this->releasedLoanFor();
        $body = ['source_loan_id' => $source->id, 'principal_amount' => 50000];

        $this->actingAs($this->userWith('loans:create', 'loans:update'));
        $this->postJson('/api/loans/preview', $body)->assertForbidden();

        $this->actingAs($this->userWith('loans:restructure'));
        $this->postJson('/api/loans/preview', $body)
            ->assertOk()
            ->assertJsonPath('data.restructure.remarks_required', true)
            ->assertJsonPath('data.restructure.write_off_permission_required', true);
    }

    public function test_a_restructure_preview_above_the_balance_states_no_shortfall(): void
    {
        $source = $this->releasedLoanFor();

        $restructure = $this->postJson('/api/loans/preview', [
            'source_loan_id' => $source->id,
            'principal_amount' => 80000,
        ])->assertOk()->json('data.restructure');

        $this->assertEqualsWithDelta(70800.00, (float) $restructure['restructurable_balance'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $restructure['shortfall'], 0.001);
        $this->assertFalse($restructure['remarks_required']);
        $this->assertFalse($restructure['write_off_permission_required']);
    }

    public function test_a_restructure_preview_of_a_loan_that_cannot_be_restructured_is_refused(): void
    {
        $draft = $this->draftLoan();

        $this->postJson('/api/loans/preview', ['source_loan_id' => $draft->id, 'principal_amount' => 1000])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    // ── the source's closed periods ──────────────────────────────────────

    public function test_a_restructure_release_marks_the_sources_closed_period_with_the_new_loan(): void
    {
        $source = $this->releasedLoanFor();
        $this->payLoan($source, 5000);

        $loans = app(LoanService::class);
        $newLoan = $loans->restructure($source, [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => 70800 - 5000,
            'start_date' => now()->toDateString(),
        ], $this->admin);
        $loans->submitForReview($newLoan);
        $loans->approve($newLoan, $this->admin, 'ok');
        $loans->release($newLoan->fresh(), $this->admin);

        $closed = AmortizationSchedule::where('loan_id', $source->id)->sole();

        $this->assertSame($newLoan->id, $closed->closed_by_loan_id);
        $this->assertNull($closed->closed_by_adjustment_id);
        $this->assertTrue($closed->isClosedEarly());
    }
}
