<?php

namespace Tests\Feature\Savings\Servicing;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use Illuminate\Support\Carbon;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Staff reactivation of a dormant account is a `reactivation` approval (gap
 * round S4, Savings TASK 20): one checker who isn't the maker, re-checked
 * under the account lock. Unfreezing is unchanged, and a member's deposit
 * still reactivates an account by itself.
 */
class ReactivationApprovalTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

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

    private function reactivationAudits(): array
    {
        return AuditLog::query()->where('auditable_type', SavingsAccount::class)->where('auditable_id', $this->dormant->id)
            ->where('action', 'savings_account_reactivated')->pluck('description')->all();
    }

    public function test_reactivating_a_dormant_account_waits_for_a_checker_who_is_not_the_maker(): void
    {
        $maker = $this->userWith(['savings:view', 'savings:close', 'savings:approve']);
        $this->actingAs($maker);

        $response = $this->postJson("/api/savings/accounts/{$this->dormant->id}/status", ['status' => 'active', 'reason' => 'Member came in with ID'])
            ->assertStatus(202)
            ->assertJsonPath('data.approval.kind', 'reactivation')
            ->assertJsonPath('data.approval.status', 'pending')
            ->assertJsonPath('data.approval.amount', '0.00')
            ->assertJsonPath('data.approval.required_checkers', 1)
            ->assertJsonPath('data.approval.account_number', $this->dormant->account_number);
        $this->assertSame('dormant', $this->dormant->refresh()->status);
        $this->assertSame([], $this->reactivationAudits());

        $approvalId = $response->json('data.approval.id');
        $this->postJson("/api/savings/approvals/{$approvalId}/approve")->assertForbidden();
        $this->assertSame('dormant', $this->dormant->refresh()->status);

        $this->postJson("/api/savings/accounts/{$this->dormant->id}/status", ['status' => 'active', 'reason' => 'Again'])->assertConflict();

        $checker = $this->checker();
        $this->actingAs($checker)->postJson("/api/savings/approvals/{$approvalId}/approve")->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->dormant->refresh();
        $this->assertSame('active', $this->dormant->status);
        $this->assertNull($this->dormant->dormant_since);
        $this->assertSame('2026-11-10', $this->dormant->last_member_activity_on->toDateString());
        $this->assertNull(SavingsApproval::query()->find($approvalId)->decision_reason);

        $audits = $this->reactivationAudits();
        $this->assertCount(1, $audits);
        $this->assertStringContainsString("at the request of {$maker->full_name}, approved by {$checker->full_name}: Member came in with ID.", $audits[0]);
    }

    public function test_an_account_a_deposit_reactivated_meanwhile_ends_the_approval_as_no_longer_needed(): void
    {
        $this->actingAs($this->teller());
        $approvalId = $this->postJson("/api/savings/accounts/{$this->dormant->id}/status", ['status' => 'active', 'reason' => 'Member came in'])
            ->assertStatus(202)->json('data.approval.id');

        $this->fund($this->dormant, 50_000);
        $this->assertSame('active', $this->dormant->refresh()->status);
        $this->assertCount(1, $this->reactivationAudits());

        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$approvalId}/approve")->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decision_reason', "No longer needed: {$this->dormant->account_number} is already active (money paid in reactivated it). Nothing was changed.");

        $this->assertSame('active', $this->dormant->refresh()->status);
        $this->assertCount(1, $this->reactivationAudits());
    }

    public function test_a_dormant_account_frozen_meanwhile_is_left_frozen(): void
    {
        $this->actingAs($this->teller());
        $approvalId = $this->postJson("/api/savings/accounts/{$this->dormant->id}/status", ['status' => 'active', 'reason' => 'Member came in'])->json('data.approval.id');
        $this->postJson("/api/savings/accounts/{$this->dormant->id}/status", ['status' => 'frozen', 'reason' => 'Court order'])->assertOk();

        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$approvalId}/approve")->assertOk()
            ->assertJsonPath('data.decision_reason', "No longer needed: {$this->dormant->account_number} is frozen now; unfreeze it first. Nothing was changed.");
        $this->assertSame('frozen', $this->dormant->refresh()->status);
        $this->assertSame('2026-11-01', $this->dormant->dormant_since->toDateString());
    }

    public function test_unfreezing_is_unchanged_and_needs_no_approval(): void
    {
        $this->actingAs($this->teller());
        $active = $this->account(['borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active'])->id]);

        $this->postJson("/api/savings/accounts/{$active->id}/status", ['status' => 'frozen', 'reason' => 'Dispute'])->assertOk()->assertJsonPath('data.status', 'frozen');
        $this->postJson("/api/savings/accounts/{$active->id}/status", ['status' => 'active', 'reason' => 'Resolved'])->assertOk()->assertJsonPath('data.status', 'active');

        $this->postJson("/api/savings/accounts/{$this->dormant->id}/status", ['status' => 'frozen', 'reason' => 'Dispute'])->assertOk();
        $this->postJson("/api/savings/accounts/{$this->dormant->id}/status", ['status' => 'active', 'reason' => 'Resolved'])->assertOk()
            ->assertJsonPath('data.status', 'dormant');
        $this->assertSame(0, SavingsApproval::query()->where('kind', 'reactivation')->count());
    }

    public function test_a_member_deposit_still_reactivates_a_dormant_account_by_itself(): void
    {
        $this->actingAs($this->teller());
        $this->money("/api/savings/accounts/{$this->dormant->id}/deposits", ['amount' => '100.00', 'payment_method' => 'cash', 'date' => '2026-11-10'])->assertCreated();

        $this->assertSame('active', $this->dormant->refresh()->status);
        $this->assertCount(1, $this->reactivationAudits());
        $this->assertSame(0, SavingsApproval::query()->where('kind', 'reactivation')->count());
    }
}
