<?php

namespace Tests\Feature\Savings\Servicing;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\PortalUser;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsProduct;
use App\Models\SavingsProductRate;
use App\Models\SavingsSetting;
use App\Models\User;
use App\Services\Savings\Api\StatusChanger;
use App\Services\Savings\ApprovalGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The permission review of PR #307 (gap round S4).
 *
 * - M1: money paid in still reactivates a dormant account, but the first
 *   money going out of it that day needs a checker, whatever the amount;
 *   a transfer out (which has no approval step) waits for the next day.
 * - L1: unfreezing an account past its dormancy cut-off makes it dormant.
 * - L3: a note's audit row holds its id and length, never its text.
 * - L4: a write rolled back after its audit row tells nobody.
 * - The second checker of a two-checker request is told when the first
 *   approves. The dormant CSV takes `list=approaching`.
 */
class ReviewFixesTest extends TestCase
{
    use PortalTestHelpers, SavingsAccountsTestHelpers, SetupLendyPH;

    private const HOLD_MESSAGE = 'This account was reactivated by a deposit, so a checker must approve money going out of it until one outflow has been approved.';

    private const CHECK_REASON = 'This account was reactivated by a deposit. Approving this lifts the hold on its other outflows, so confirm the member is present.';

    private SavingsAccount $dormant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        $this->dormant = $this->account(['status' => 'dormant', 'dormant_since' => '2026-11-01', 'last_member_activity_on' => '2024-10-31', 'opened_on' => '2024-01-02']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function withdrawal(SavingsAccount $account, string $amount = '500.00'): TestResponse
    {
        return $this->money("/api/savings/accounts/{$account->id}/withdrawals", ['amount' => $amount, 'date' => now()->toDateString(), 'payment_method' => 'cash']);
    }

    // ── M1 ──

    private function reactivateByDeposit(string $amount = '5000.00'): void
    {
        $this->actingAs($this->teller());
        $this->money("/api/savings/accounts/{$this->dormant->id}/deposits", ['amount' => $amount, 'date' => now()->toDateString(), 'payment_method' => 'cash'])->assertCreated();
        $this->assertSame('active', $this->dormant->refresh()->status);
    }

    public function test_a_small_withdrawal_after_a_deposit_reactivation_needs_a_checker_and_the_first_approval_lifts_the_hold(): void
    {
        $this->reactivateByDeposit();
        $this->assertSame('2026-11-10', $this->dormant->outflow_check_required_since->toDateString());

        $approval = $this->withdrawal($this->dormant)->assertStatus(202)
            ->assertJsonPath('message', self::HOLD_MESSAGE)
            ->assertJsonPath('data.approval.kind', 'withdrawal')
            ->assertJsonPath('data.approval.required_checkers', 1)
            ->assertJsonPath('data.approval.check_reason', self::CHECK_REASON)
            ->json('data.approval');
        $this->assertSame('5000.00', $this->balanceOf($this->dormant));

        $checker = $this->checker();
        $this->actingAs($checker)->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk();
        $this->assertSame('4500.00', $this->balanceOf($this->dormant));
        $this->assertNull($this->dormant->refresh()->outflow_check_required_since);
        $this->assertStringContainsString(
            "The hold on money going out of savings account {$this->dormant->account_number} after its reactivation by a deposit was lifted when {$checker->full_name} approved request #{$approval['id']}.",
            (string) AuditLog::query()->where('action', 'savings_outflow_hold_lifted')->where('auditable_id', $this->dormant->id)->value('description'),
        );

        $this->actingAs($this->teller());
        $this->withdrawal($this->dormant, '100.00')->assertCreated();
    }

    public function test_a_withdrawal_above_tier_1_on_a_held_account_also_tells_the_checker(): void
    {
        $this->reactivateByDeposit('50000.00');

        $approval = $this->withdrawal($this->dormant, '20000.00')->assertStatus(202)
            ->assertJsonPath('message', self::HOLD_MESSAGE)
            ->assertJsonPath('data.approval.required_checkers', 1)
            ->assertJsonPath('data.approval.check_reason', self::CHECK_REASON)
            ->json('data.approval');

        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk();
        $this->assertNull($this->dormant->refresh()->outflow_check_required_since);

        $this->actingAs($this->teller());
        $this->withdrawal($this->dormant, '20000.00')->assertStatus(202)
            ->assertJsonPath('data.approval.check_reason', null)
            ->assertJsonMissingPath('message');
    }

    public function test_the_hold_survives_midnight_until_an_outflow_is_approved(): void
    {
        $this->reactivateByDeposit();

        $this->on('2026-11-11', function (): void {
            $this->withdrawal($this->dormant)->assertStatus(202)->assertJsonPath('message', self::HOLD_MESSAGE);
        });

        $this->on('2026-11-12', function (): void {
            $approvalId = $this->withdrawal($this->dormant)->assertStatus(202)->json('data.approval.id');
            $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$approvalId}/reject", ['reason' => 'Member not present'])->assertOk();
            $this->actingAs($this->teller());
            $this->withdrawal($this->dormant)->assertStatus(202);
        });

        $this->assertSame('2026-11-10', $this->dormant->refresh()->outflow_check_required_since->toDateString());
    }

    public function test_transfers_out_and_placements_are_refused_and_a_close_needs_a_checker_while_held(): void
    {
        $second = SavingsProduct::factory()->create(['name' => 'Second Savings', 'minimum_opening_balance' => '0.00']);
        SavingsProductRate::factory()->create(['product_id' => $second->id, 'annual_rate' => '1.0000', 'effective_from' => '2026-01-01']);
        $other = $this->account(['product_id' => $second->id]);
        $this->reactivateByDeposit('150000.00');

        $this->on('2026-11-11', function () use ($other): void {
            $this->money("/api/savings/accounts/{$this->dormant->id}/transfers", ['to_account_id' => $other->id, 'amount' => '100.00', 'date' => '2026-11-11'])
                ->assertUnprocessable()
                ->assertJsonPath('errors.amount.0', self::HOLD_MESSAGE.' A transfer has no approval step: make a withdrawal for a checker to approve first.');
        });

        $timeDeposit = SavingsProduct::factory()->timeDeposit()->create(['pretermination_product_id' => $this->product->id]);
        SavingsProductRate::query()->create(['product_id' => $timeDeposit->id, 'term_days' => 90, 'annual_rate' => '3.0000', 'effective_from' => '2026-01-01', 'created_by' => $this->admin->id]);
        $this->actingAs($this->admin);
        $this->money('/api/savings/time-deposits', [
            'borrower_id' => $this->member->id, 'product_id' => $timeDeposit->id, 'principal' => '100000.00', 'term_days' => 90,
            'placement_date' => '2026-11-10', 'maturity_instruction' => 'payout', 'payment_method' => 'savings', 'funding_account_id' => $this->dormant->id,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.funding_account_id.0', self::HOLD_MESSAGE.' A time deposit placement has no approval step: make a withdrawal for a checker to approve first, or pay for the placement in cash.');
        $this->assertSame('150000.00', $this->balanceOf($this->dormant));
    }

    public function test_a_small_close_needs_a_checker_while_held(): void
    {
        $this->reactivateByDeposit();

        $this->on('2026-11-11', fn () => $this->money("/api/savings/accounts/{$this->dormant->id}/close", ['date' => '2026-11-11', 'reason' => 'Member request', 'payout_method' => 'cash'])
            ->assertStatus(202)->assertJsonPath('data.approval.kind', 'close')
            ->assertJsonPath('message', self::HOLD_MESSAGE)->assertJsonPath('data.approval.check_reason', self::CHECK_REASON));
    }

    public function test_a_small_share_capital_transfer_needs_a_checker_while_held(): void
    {
        $this->reactivateByDeposit();
        $officer = $this->userWith(['savings:view', 'savings:post', 'share_capital:create', 'share_capital:view']);

        $approvalId = $this->actingAs($officer)->money("/api/savings/accounts/{$this->dormant->id}/share-capital-transfers", ['amount' => '500.00', 'remarks' => 'Build-up'])
            ->assertStatus(202)
            ->assertJsonPath('data.approval.kind', 'share_capital_transfer')
            ->assertJsonPath('data.approval.check_reason', self::CHECK_REASON)
            ->json('data.approval.id');
        $this->assertSame('5000.00', $this->balanceOf($this->dormant));

        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$approvalId}/approve")->assertOk();
        $this->assertSame('4500.00', $this->balanceOf($this->dormant));
        $this->assertNull($this->dormant->refresh()->outflow_check_required_since);

        $this->actingAs($officer)->money("/api/savings/accounts/{$this->dormant->id}/share-capital-transfers", ['amount' => '100.00'])->assertCreated();
    }

    public function test_an_account_never_reactivated_by_a_deposit_is_unchanged(): void
    {
        $active = $this->account(['borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active'])->id]);
        $this->fund($active, 500_000);
        $this->actingAs($this->teller());
        $this->withdrawal($active)->assertCreated();

        $this->assertFalse(app(ApprovalGate::class)->needsApprovalFor($active->refresh(), 50_000));
        $this->assertNull($active->outflow_check_required_since);
    }

    // ── L1 ──

    public function test_unfreezing_an_account_past_its_dormancy_cutoff_makes_it_dormant(): void
    {
        $stale = $this->account(['borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active'])->id, 'status' => 'frozen', 'last_member_activity_on' => '2024-11-10', 'opened_on' => '2024-01-02']);
        $byOpening = $this->account(['borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active'])->id, 'status' => 'frozen', 'last_member_activity_on' => null, 'opened_on' => '2024-11-10']);
        $recent = $this->account(['borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active'])->id, 'status' => 'frozen', 'last_member_activity_on' => '2024-11-11', 'opened_on' => '2024-01-02']);

        $this->actingAs($this->teller());
        $this->postJson("/api/savings/accounts/{$stale->id}/status", ['status' => 'active', 'reason' => 'Resolved'])->assertOk()
            ->assertJsonPath('data.status', 'dormant')->assertJsonPath('data.dormant_since', '2026-11-10');
        $this->postJson("/api/savings/accounts/{$byOpening->id}/status", ['status' => 'active', 'reason' => 'Resolved'])->assertOk()
            ->assertJsonPath('data.status', 'dormant');
        $this->postJson("/api/savings/accounts/{$recent->id}/status", ['status' => 'active', 'reason' => 'Resolved'])->assertOk()
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.dormant_since', null);

        $this->assertStringContainsString('unfrozen (dormant: no member activity since Nov 10, 2024)', (string) AuditLog::query()
            ->where('auditable_id', $stale->id)->where('auditable_type', SavingsAccount::class)->where('action', 'savings_account_unfrozen')->value('description'));
    }

    // ── L3 ──

    public function test_a_notes_audit_row_holds_its_id_and_length_never_its_text(): void
    {
        $poster = $this->userWith(['savings:view', 'savings:post']);
        $id = $this->actingAs($poster)->postJson("/api/savings/accounts/{$this->dormant->id}/notes", ['note' => 'Member says her ID is 1234-5678'])
            ->assertCreated()->json('data.id');

        $audit = AuditLog::query()->where('action', 'savings_account_note_added')->firstOrFail();
        $this->assertEquals(['note_id' => $id, 'length' => 31], $audit->new_values);
        $this->assertSame("Note #{$id} (31 characters) added to savings account {$this->dormant->account_number} by {$poster->full_name}.", $audit->description);
        $this->assertStringNotContainsString('1234', json_encode($audit->toArray()));
    }

    // ── L4 ──

    public function test_a_write_rolled_back_after_its_audit_row_tells_nobody(): void
    {
        $this->enablePortal();
        $portalUser = PortalUser::factory()->create(['borrower_id' => $this->member->id]);
        $checker = $this->checker();
        $maker = $this->teller();

        try {
            DB::transaction(function () use ($maker): void {
                app(StatusChanger::class)->change($this->dormant->id, 'frozen', 'Court order', $maker);
                app(StatusChanger::class)->change($this->account(['borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id, 'status' => 'dormant', 'dormant_since' => '2026-11-01'])->id, 'active', 'Came in', $maker);
                $this->assertTrue(AuditLog::query()->where('action', 'savings_account_frozen')->exists());
                throw new RuntimeException('The close failed.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('dormant', $this->dormant->refresh()->status);
        $this->assertSame(0, SavingsApproval::query()->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_type', PortalUser::class)->where('notifiable_id', $portalUser->id)->count());
        $this->assertSame(0, DB::table('notifications')->where('type', 'savings_approval_waiting')->where('notifiable_id', $checker->id)->count());
    }

    // ── The second checker ──

    public function test_after_the_first_approval_the_remaining_checkers_are_told_it_waits_for_them(): void
    {
        $account = $this->account(['borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active', 'first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Cruz', 'suffix' => null])->id]);
        $this->fund($account, 10_000_000);
        $maker = $this->userWith(['savings:view', 'savings:transact', 'savings:approve']);
        $first = $this->checker();
        $second = $this->checker();

        $approval = $this->actingAs($maker)->withdrawal($account, '60000.00')->assertStatus(202)->json('data.approval');
        $this->assertSame(2, $approval['required_checkers']);
        $before = $this->told($second);

        $this->actingAs($first)->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk()->assertJsonPath('data.status', 'pending');

        $rows = DB::table('notifications')->where('type', 'savings_approval_waiting')->get()->groupBy('notifiable_id');
        $this->assertCount($before + 1, $rows[$second->id]);
        $this->assertCount(1, $rows[$first->id]);
        $this->assertArrayNotHasKey($maker->id, $rows->all());

        $latest = $rows[$second->id]->map(fn ($row) => json_decode($row->data, true))->firstWhere('data.checkers_left', 1);
        $this->assertSame("A withdrawal on {$account->account_number} (Ana Cruz) for ₱60,000.00 has its first approval from {$first->full_name} and is waiting for a second checker, requested by {$maker->full_name}.", $latest['body']);
    }

    private function told(User $user): int
    {
        return DB::table('notifications')->where('type', 'savings_approval_waiting')->where('notifiable_id', $user->id)->count();
    }

    // ── The approaching CSV ──

    public function test_the_dormant_csv_takes_the_approaching_list(): void
    {
        SavingsSetting::current();
        $soon = $this->account(['borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active'])->id, 'last_member_activity_on' => '2024-12-20', 'opened_on' => '2024-01-02', 'ledger_balance' => '10.00']);

        $this->actingAs($this->userWith(['reports:view', 'reports:export', 'savings:view']));
        $csv = $this->get('/api/reports/savings/dormant/export?list=approaching', ['Accept' => 'application/json'])->assertOk()->streamedContent();

        $this->assertStringContainsString('"Turns Dormant On","Days Left"', str_replace("\u{FEFF}", '', $csv));
        $this->assertStringContainsString($soon->account_number, $csv);
        $this->assertStringContainsString('2026-12-20', $csv);
        $this->assertStringNotContainsString($this->dormant->account_number, $csv);

        $this->get('/api/reports/savings/dormant/export?list=sleepy', ['Accept' => 'application/json'])->assertUnprocessable();
    }
}
