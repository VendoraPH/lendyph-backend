<?php

namespace Tests\Feature\Phase6\Branches;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\BranchClosure;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\PortalRequest;
use App\Models\PortalUser;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BranchAccess;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE1 (multi-branch decision 3): closing a branch takes an
 * effective date and a second person. One user asks, a different one
 * approves, and either step is refused while the branch still has open loans,
 * savings or time deposits, money in its cash and bank accounts, pending
 * approvals or requests, or assigned users.
 */
class BranchClosureTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private Branch $quiet;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->quiet = Branch::factory()->create(['name' => 'Quiet Branch', 'code' => 'QUI']);
        $this->checker = User::factory()->inBranches($this->branch)->create(['first_name' => 'Carla', 'last_name' => 'Reyes']);
        $this->checker->givePermissionTo(['branches:manage', BranchAccess::VIEW_ALL]);
    }

    public function test_a_quiet_branch_is_closed_by_two_people_on_its_effective_date(): void
    {
        $effective = now()->subDay()->toDateString();

        $closureId = $this->postJson("/api/branches/{$this->quiet->id}/closure", ['effective_on' => $effective, 'reason' => 'Merged into the main office'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.effective_on', $effective)
            ->assertJsonPath('data.requested_by.id', $this->admin->id)
            ->json('data.id');

        $this->assertSame('active', $this->quiet->fresh()->status);

        $this->actingAs($this->checker);
        $this->postJson("/api/branches/{$this->quiet->id}/closure/approve", ['note' => 'Checked'])
            ->assertOk()
            ->assertJsonPath('data.id', $closureId)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decided_by.full_name', 'Carla Reyes');

        $branch = $this->quiet->fresh();
        $this->assertSame('closed', $branch->status);
        $this->assertFalse($branch->is_active);

        $approved = $this->auditRows($this->quiet->id)->firstWhere('action', 'branch_closure_approved');
        $this->assertSame($this->checker->id, $approved->user_id);
        // assertEquals: the JSON column keeps its own key order.
        $this->assertEquals(['closure_id' => $closureId, 'status' => 'approved', 'decision_note' => 'Checked'], $approved->new_values);
        $this->assertSame($effective, $branch->closed_on->toDateString());

        $this->assertSame([
            ['branch_closure_requested', sprintf('Closing branch "Quiet Branch" (QUI) on %s was requested. Reason: Merged into the main office', $this->words($effective))],
            ['branch_closure_approved', sprintf('The request to close branch "Quiet Branch" (QUI) on %s was approved. Note: Checked', $this->words($effective))],
            ['updated', sprintf('Branch "Quiet Branch" (QUI) closed on %s, requested by %s and approved by Carla Reyes.', $this->words($effective), $this->admin->full_name)],
        ], $this->auditRows($this->quiet->id)->map(fn (AuditLog $row) => [$row->action, $row->description])->all());
    }

    public function test_a_branch_waiting_to_close_cannot_become_the_main_office(): void
    {
        $this->requestClosure();

        $this->putJson("/api/branches/{$this->quiet->id}/main-office", ['reason' => 'Head office moved'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', 'A request to close this branch is waiting. Reject or cancel it before making it the main office.');

        $this->assertFalse($this->quiet->fresh()->is_main_office);
        $this->assertSame('pending', BranchClosure::query()->where('branch_id', $this->quiet->id)->sole()->status);

        // Once the request is cancelled, the move goes ahead.
        $this->deleteJson("/api/branches/{$this->quiet->id}/closure")->assertOk();
        $this->putJson("/api/branches/{$this->quiet->id}/main-office", ['reason' => 'Head office moved'])->assertOk();
    }

    public function test_the_person_who_asked_cannot_approve(): void
    {
        $this->requestClosure();

        $this->postJson("/api/branches/{$this->quiet->id}/closure/approve")
            ->assertForbidden()
            ->assertJsonPath('message', 'You asked to close this branch, so someone else has to approve it.');

        $this->assertSame('active', $this->quiet->fresh()->status);
    }

    public function test_a_requester_who_cancels_and_asks_again_still_cannot_approve(): void
    {
        $this->requestClosure();
        $this->deleteJson("/api/branches/{$this->quiet->id}/closure")->assertOk();
        $this->requestClosure();

        $this->postJson("/api/branches/{$this->quiet->id}/closure/approve")
            ->assertForbidden()
            ->assertJsonPath('message', 'You asked to close this branch, so someone else has to approve it.');
        $this->assertSame('active', $this->quiet->fresh()->status);
    }

    public function test_the_database_lets_one_request_wait_per_branch(): void
    {
        $row = ['branch_id' => $this->quiet->id, 'status' => 'pending', 'effective_on' => now()->toDateString(), 'reason' => 'x', 'requested_by' => $this->admin->id];
        BranchClosure::create($row);
        BranchClosure::create([...$row, 'status' => 'cancelled']);

        $this->expectException(UniqueConstraintViolationException::class);
        BranchClosure::create($row);
    }

    public function test_the_checklist_lists_everything_that_keeps_a_branch_open(): void
    {
        $this->seedChartOfAccounts();
        $member = AuditLogService::withoutModelAuditing(fn () => Borrower::factory()->create(['branch_id' => $this->quiet->id, 'status' => 'active']));
        $loan = Loan::factory()->create(['borrower_id' => $member->id, 'branch_id' => $this->quiet->id, 'status' => 'ongoing']);
        Loan::factory()->create(['borrower_id' => $member->id, 'branch_id' => $this->quiet->id, 'status' => 'completed']);
        LoanAdjustment::factory()->create(['loan_id' => $loan->id, 'status' => 'pending']);
        $account = SavingsAccount::factory()->create(['borrower_id' => $member->id, 'branch_id' => $this->quiet->id]);
        SavingsAccount::factory()->closed()->create(['borrower_id' => $member->id, 'branch_id' => $this->quiet->id]);
        SavingsApproval::factory()->create(['account_id' => $account->id, 'status' => 'pending']);
        TimeDeposit::factory()->create(['borrower_id' => $member->id, 'branch_id' => $this->quiet->id, 'status' => 'active']);
        PortalRequest::factory()->create([
            'portal_user_id' => PortalUser::factory()->create(['borrower_id' => $member->id])->id,
            'branch_id' => $this->quiet->id,
        ]);
        $this->postSimpleJournal('1010', '3050', 150000, ['branch_id' => $this->quiet->id]);
        User::factory()->inBranches($this->quiet)->create();

        $data = $this->getJson("/api/branches/{$this->quiet->id}/closure")->assertOk()->json('data');

        $this->assertFalse($data['can_close']);
        $this->assertNull($data['request']);
        $this->assertSame([
            ['key' => 'open_loans', 'label' => 'Open loans', 'count' => 1, 'amount' => null, 'clear' => false],
            ['key' => 'open_savings_accounts', 'label' => 'Open savings accounts', 'count' => 1, 'amount' => null, 'clear' => false],
            ['key' => 'open_time_deposits', 'label' => 'Open time deposits', 'count' => 1, 'amount' => null, 'clear' => false],
            ['key' => 'money_in_accounts', 'label' => 'Money in its cash and bank accounts', 'count' => 1, 'amount' => '1500.00', 'clear' => false],
            ['key' => 'pending_approvals', 'label' => 'Pending approvals and requests', 'count' => 3, 'amount' => null, 'clear' => false],
            ['key' => 'transfers_in_transit', 'label' => 'Transfers in transit', 'count' => 0, 'amount' => null, 'clear' => true],
            ['key' => 'assigned_users', 'label' => 'Assigned users', 'count' => 1, 'amount' => null, 'clear' => false],
        ], $data['checklist']);

        $this->postJson("/api/branches/{$this->quiet->id}/closure", ['effective_on' => now()->toDateString(), 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', "This branch can't be closed yet: 1 open loan, 1 open savings account, 1 open time deposit, ₱1,500.00 in its cash and bank accounts, 3 pending approvals or requests and 1 assigned user.");
    }

    public function test_a_quiet_branch_has_a_clear_checklist(): void
    {
        $data = $this->getJson("/api/branches/{$this->quiet->id}/closure")->assertOk()->json('data');

        $this->assertTrue($data['can_close']);
        $this->assertSame([true], array_values(array_unique(array_column($data['checklist'], 'clear'))));
    }

    public function test_approval_checks_the_branch_again(): void
    {
        $this->requestClosure();
        User::factory()->inBranches($this->quiet)->create();

        $this->actingAs($this->checker);
        $this->postJson("/api/branches/{$this->quiet->id}/closure/approve")
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', "This branch can't be closed yet: 1 assigned user.");

        $this->assertSame('active', $this->quiet->fresh()->status);
        $this->assertSame('pending', BranchClosure::query()->where('branch_id', $this->quiet->id)->sole()->status);
    }

    public function test_the_effective_date_and_reason_are_required_and_the_date_is_not_in_the_future(): void
    {
        $this->postJson("/api/branches/{$this->quiet->id}/closure", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['effective_on', 'reason']);

        $this->postJson("/api/branches/{$this->quiet->id}/closure", ['effective_on' => now()->addDay()->toDateString(), 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('effective_on');

        $this->quiet->update(['opened_on' => now()->subDays(3)->toDateString()]);
        $this->postJson("/api/branches/{$this->quiet->id}/closure", ['effective_on' => now()->subDays(4)->toDateString(), 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.effective_on.0', 'The closing date cannot be before the branch opened.');
    }

    public function test_one_request_waits_at_a_time(): void
    {
        $this->requestClosure();

        $this->postJson("/api/branches/{$this->quiet->id}/closure", ['effective_on' => now()->toDateString(), 'reason' => 'again'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'A request to close this branch is already waiting. Approve, reject or cancel it first.');
    }

    public function test_the_main_office_and_a_closed_branch_cannot_be_closed(): void
    {
        $this->putJson("/api/branches/{$this->quiet->id}/main-office", ['reason' => 'x'])->assertOk();
        $this->postJson("/api/branches/{$this->quiet->id}/closure", ['effective_on' => now()->toDateString(), 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', 'The main office stays open. Make another branch the main office first.');

        $closed = Branch::factory()->create(['status' => 'closed']);
        $this->postJson("/api/branches/{$closed->id}/closure", ['effective_on' => now()->toDateString(), 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', 'This branch is already closed.');
    }

    public function test_another_user_rejects_and_the_requester_cancels(): void
    {
        $this->requestClosure();

        $this->actingAs($this->checker);
        $this->postJson("/api/branches/{$this->quiet->id}/closure/reject", [])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->deleteJson("/api/branches/{$this->quiet->id}/closure")
            ->assertForbidden()
            ->assertJsonPath('message', 'Only the person who asked to close this branch can cancel the request.');
        $this->postJson("/api/branches/{$this->quiet->id}/closure/reject", ['note' => 'Not this year'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.decision_note', 'Not this year');

        $this->actingAs($this->admin);
        $this->requestClosure();
        $this->postJson("/api/branches/{$this->quiet->id}/closure/reject", ['note' => 'x'])
            ->assertForbidden()
            ->assertJsonPath('message', 'You asked to close this branch, so someone else has to reject it. You can cancel it instead.');
        $this->deleteJson("/api/branches/{$this->quiet->id}/closure")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('active', $this->quiet->fresh()->status);
        $this->postJson("/api/branches/{$this->quiet->id}/closure/approve")
            ->assertNotFound()
            ->assertJsonPath('message', 'No request to close this branch is waiting.');
        $this->assertSame(
            ['branch_closure_requested', 'branch_closure_rejected', 'branch_closure_requested', 'branch_closure_cancelled'],
            $this->auditRows($this->quiet->id)->pluck('action')->all(),
        );
    }

    public function test_closure_needs_branch_management_and_view_all(): void
    {
        $message = "Changing a branch's status needs the branch management permission and access to all branches.";
        $user = User::factory()->inBranches($this->quiet)->create();
        $user->givePermissionTo(['branches:manage', 'settings:update']);
        $this->actingAs($user);

        $this->getJson("/api/branches/{$this->quiet->id}/closure")->assertForbidden()->assertJsonPath('message', $message);
        $this->postJson("/api/branches/{$this->quiet->id}/closure", ['effective_on' => now()->toDateString(), 'reason' => 'x'])
            ->assertForbidden()->assertJsonPath('message', $message);
        $this->getJson("/api/branches/{$this->branch->id}/closure")->assertNotFound();
    }

    private function requestClosure(): void
    {
        $this->postJson("/api/branches/{$this->quiet->id}/closure", ['effective_on' => now()->toDateString(), 'reason' => 'Closing'])->assertCreated();
    }

    private function words(string $date): string
    {
        return Carbon::parse($date)->format('M j, Y');
    }

    private function auditRows(int $branchId)
    {
        return AuditLog::query()
            ->where('auditable_type', (new Branch)->getMorphClass())
            ->where('auditable_id', $branchId)
            ->whereNotIn('action', ['created'])
            ->orderBy('id')
            ->get();
    }
}
