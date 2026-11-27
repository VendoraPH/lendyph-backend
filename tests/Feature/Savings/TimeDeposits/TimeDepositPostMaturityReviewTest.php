<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\AuditLog;
use App\Models\SavingsApproval;
use App\Models\SavingsProduct;
use App\Models\SavingsTaxRule;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\Savings\ApprovalGate;
use App\Services\Savings\SavingsLocks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The post-maturity review: an approved late payout pays exactly its
 * approval, with the tax rate snapshotted at request; the nightly job
 * settling days late; the presenter's live and stored figures agreeing; the
 * pre-termination product snapshotted at placement (and backfilled); a row
 * whose figure can't be worked out never fails the list.
 *
 * The fixtures: ₱100,000.00 at 3% for 90 days matures on Feb 8, 2027 at
 * ₱100,739.73; the regular rate is 1%.
 */
class TimeDepositPostMaturityReviewTest extends TestCase
{
    use TimeDepositFixtures;

    private const MIGRATION = '2026_11_10_503000_add_pretermination_product_id_to_time_deposits';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTimeDeposits();
    }

    protected function tearDown(): void
    {
        $this->tearDownTimeDeposits();
        parent::tearDown();
    }

    private function placed(array $overrides = []): TimeDeposit
    {
        return TimeDeposit::query()->findOrFail($this->place($overrides)->assertCreated()->json('data.id'));
    }

    private function execute(int $approvalId, User $checker): void
    {
        $approval = SavingsApproval::query()->findOrFail($approvalId);
        $executor = app(ApprovalGate::class)->executor($approval->kind);

        DB::transaction(function () use ($executor, $approval, $checker): void {
            app(SavingsLocks::class)->timeDeposits($executor->timeDepositIds($approval));
            $executor->execute($approval, $checker);
        });
    }

    public function test_an_approved_late_cash_payout_pays_exactly_the_approval_amount(): void
    {
        $deposit = $this->placed();
        $this->travelTo(Carbon::parse('2027-02-22 09:00:00', 'Asia/Manila'));

        $approval = $this->idem("/api/savings/time-deposits/{$deposit->id}/settle", ['date' => '2027-02-22', 'action' => 'payout', 'payout_method' => 'cash'])
            ->assertStatus(202)->json('data.approval');
        $this->execute($approval['id'], User::factory()->create(['branch_id' => $this->branch->id]));

        $deposit->refresh();
        $this->assertSame('100778.37', $approval['amount']);
        $this->assertSame($approval['amount'], $deposit->settled_amount);
        $this->assertSame('778.37', $deposit->settled_interest);
        $journal = $this->journalFor(TimeDeposit::class, $deposit->id, 'time_deposit_settlement');
        $this->assertSame([['2060', 10_000_000, 0], ['5160', 77_837, 0], ['1010', 0, 10_077_837]], $this->lines($journal));
    }

    public function test_a_tax_rule_added_after_the_request_doesnt_change_the_approved_payout(): void
    {
        $deposit = $this->placed();
        $this->travelTo(Carbon::parse('2027-02-22 09:00:00', 'Asia/Manila'));
        $approval = $this->idem("/api/savings/time-deposits/{$deposit->id}/settle", ['date' => '2027-02-22', 'action' => 'payout', 'payout_method' => 'cash'])
            ->assertStatus(202)->json('data.approval');

        SavingsTaxRule::query()->create(['holder_type' => 'member', 'rate' => '20.0000', 'effective_from' => '2027-02-22']);
        $this->execute($approval['id'], User::factory()->create(['branch_id' => $this->branch->id]));

        $deposit->refresh();
        $this->assertSame('withdrawn', $deposit->status);
        $this->assertSame('100778.37', $deposit->settled_amount);
        $this->assertSame('0.00', $deposit->settled_tax);
    }

    public function test_the_nightly_job_settles_days_late_with_the_post_maturity_interest(): void
    {
        $deposit = $this->placed(['maturity_instruction' => 'rollover_all']);
        $this->product->forceFill(['is_active' => false])->save();

        $this->travelTo(Carbon::parse('2027-02-08 00:40:00', 'Asia/Manila'));
        $this->artisan('savings:mature-time-deposits')->assertSuccessful();
        $this->assertSame('matured', $deposit->refresh()->status);

        $this->product->forceFill(['is_active' => true])->save();
        $this->travelTo(Carbon::parse('2027-02-11 00:40:00', 'Asia/Manila'));
        $this->artisan('savings:mature-time-deposits')->assertSuccessful();

        // 3 days at 1% on ₱100,739.73 = 827.97 centavos → ₱8.28.
        $deposit->refresh();
        $this->assertSame('rolled_over', $deposit->status);
        $this->assertSame('2027-02-11', $deposit->settled_on->toDateString());
        $this->assertSame('748.01', $deposit->settled_interest);
        $new = TimeDeposit::query()->where('rolled_over_from_id', $deposit->id)->sole();
        $this->assertSame('100748.01', $new->principal);
        $this->assertSame('2027-02-11', $new->placement_date->toDateString());
        $this->getJson("/api/savings/time-deposits/{$deposit->id}")->assertOk()
            ->assertJsonPath('data.post_maturity_days', 3)
            ->assertJsonPath('data.post_maturity_interest', '8.28');
    }

    public function test_the_live_settle_today_figure_matches_what_the_settlement_stores(): void
    {
        $account = $this->savingsAccount();
        $deposit = $this->placed(['payout_account_id' => $account->id]);
        $this->travelTo(Carbon::parse('2027-02-22 09:00:00', 'Asia/Manila'));

        $live = $this->getJson("/api/savings/time-deposits/{$deposit->id}")->assertOk()->json('data');
        $listed = collect($this->getJson('/api/savings/time-deposits')->assertOk()->json('data'))->firstWhere('id', $deposit->id);
        $this->idem("/api/savings/time-deposits/{$deposit->id}/settle", ['date' => '2027-02-22', 'action' => 'payout', 'payout_method' => 'savings'])->assertCreated();

        $this->travelTo(Carbon::parse('2027-03-15 09:00:00', 'Asia/Manila'));
        $stored = $this->getJson("/api/savings/time-deposits/{$deposit->id}")->assertOk()->json('data');

        foreach ([$live, $listed] as $before) {
            $this->assertSame(14, $before['post_maturity_days']);
            $this->assertSame('38.64', $before['post_maturity_interest']);
        }

        $this->assertSame(14, $stored['post_maturity_days']);
        $this->assertSame('38.64', $stored['post_maturity_interest']);
    }

    public function test_post_maturity_interest_uses_the_pretermination_product_linked_at_placement(): void
    {
        $deposit = $this->placed();
        $this->assertSame($this->regular->id, $deposit->pretermination_product_id);

        $richer = SavingsProduct::factory()->create();
        $this->rate($richer, null, '5.0000');
        $this->product->forceFill(['pretermination_product_id' => $richer->id])->save();

        $this->travelTo(Carbon::parse('2027-02-22 09:00:00', 'Asia/Manila'));
        $this->getJson("/api/savings/time-deposits/{$deposit->id}")->assertOk()
            ->assertJsonPath('data.post_maturity_interest', '38.64');
    }

    public function test_a_placement_with_no_stored_link_falls_back_to_the_products_current_one(): void
    {
        $deposit = $this->placed();
        DB::table('time_deposits')->where('id', $deposit->id)->update(['pretermination_product_id' => null]);

        $this->travelTo(Carbon::parse('2027-02-22 09:00:00', 'Asia/Manila'));
        $this->getJson("/api/savings/time-deposits/{$deposit->id}")->assertOk()
            ->assertJsonPath('data.post_maturity_interest', '38.64');
    }

    public function test_the_migration_backfills_the_link_once_with_a_system_audit_row(): void
    {
        $deposit = $this->placed();
        DB::table('time_deposits')->where('id', $deposit->id)->update(['pretermination_product_id' => null]);
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');

        $migration->up();
        $migration->up();

        $this->assertSame($this->regular->id, (int) DB::table('time_deposits')->where('id', $deposit->id)->value('pretermination_product_id'));
        $row = AuditLog::query()->where('action', 'time_deposit_pretermination_product_backfilled')->where('auditable_id', $deposit->id)->sole();
        $this->assertNull($row->user_id);
        $this->assertSame(['pretermination_product_id' => null], $row->old_values);
        $this->assertSame(['pretermination_product_id' => $this->regular->id], $row->new_values);
    }

    public function test_the_nightly_job_carries_on_when_a_placements_figures_cant_be_worked_out(): void
    {
        $interestOut = $this->placed(['maturity_instruction' => 'rollover_principal']);
        $this->travelTo(Carbon::parse('2027-02-10 00:40:00', 'Asia/Manila'));
        DB::table('savings_tax_rules')->delete();

        $this->artisan('savings:mature-time-deposits')->assertSuccessful();

        $this->assertSame('matured', $interestOut->refresh()->status);
    }

    public function test_one_row_that_cant_be_worked_out_never_fails_the_list(): void
    {
        $deposit = $this->placed();
        $this->travelTo(Carbon::parse('2027-02-22 09:00:00', 'Asia/Manila'));
        DB::table('savings_tax_rules')->delete();
        Log::spy();

        $row = collect($this->getJson('/api/savings/time-deposits')->assertOk()->json('data'))->firstWhere('id', $deposit->id);

        $this->assertNull($row['post_maturity_days']);
        $this->assertNull($row['post_maturity_interest']);
        Log::shouldHaveReceived('warning')->once();
    }
}
