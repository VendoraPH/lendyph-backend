<?php

namespace Tests\Feature\Phase6\Branches;

use App\Models\AccountingExpense;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\BranchClosure;
use App\Models\GCashTransaction;
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
use Illuminate\Testing\TestResponse;
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
        $this->quiet = Branch::factory()->create(['name' => 'Quiet Branch', 'code' => 'QUI', 'status' => 'suspended']);
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

        $this->assertSame('suspended', $this->quiet->fresh()->status);

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

        // A branch waiting to close is suspended, and it can't be reactivated
        // while the request waits, so the move is refused on its status.
        $this->putJson("/api/branches/{$this->quiet->id}/main-office", ['reason' => 'Head office moved'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', 'Only an active branch can be the main office.');

        // A status set outside the API still meets the waiting request.
        Branch::query()->whereKey($this->quiet->id)->update(['status' => 'active']);
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

        $this->assertSame('suspended', $this->quiet->fresh()->status);
    }

    public function test_a_requester_who_cancels_and_asks_again_still_cannot_approve(): void
    {
        $this->requestClosure();
        $this->deleteJson("/api/branches/{$this->quiet->id}/closure")->assertOk();
        $this->requestClosure();

        $this->postJson("/api/branches/{$this->quiet->id}/closure/approve")
            ->assertForbidden()
            ->assertJsonPath('message', 'You asked to close this branch, so someone else has to approve it.');
        $this->assertSame('suspended', $this->quiet->fresh()->status);
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
            ['key' => 'pending_gcash_cash_ins', 'label' => 'GCash Cash Ins not yet paid', 'count' => 0, 'amount' => null, 'clear' => true],
            ['key' => 'unpaid_payables', 'label' => 'Unpaid payables', 'count' => 0, 'amount' => null, 'clear' => true],
            ['key' => 'auto_credit_members', 'label' => 'Members with share capital auto-credit on', 'count' => 0, 'amount' => null, 'clear' => true],
            ['key' => 'assigned_users', 'label' => 'Assigned users', 'count' => 1, 'amount' => null, 'clear' => false],
        ], $data['checklist']);

        $this->postJson("/api/branches/{$this->quiet->id}/closure", ['effective_on' => now()->toDateString(), 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', "This branch can't be closed yet: 1 open loan, 1 open savings account, 1 open time deposit, ₱1,500.00 in its cash and bank accounts, 3 pending approvals or requests and 1 assigned user.");
    }

    /**
     * A pending Cash In posts cash when it's marked paid, so a closure waits
     * for the walk-in rows recorded in the branch and its members' rows, as a
     * member transfer does (finish run round 2, review of S2). Paid rows,
     * Cash Outs and other branches' rows don't count.
     */
    public function test_closing_waits_for_the_branchs_pending_gcash_cash_ins(): void
    {
        $member = AuditLogService::withoutModelAuditing(fn () => Borrower::factory()->create(['branch_id' => $this->quiet->id, 'status' => 'active']));
        $elsewhere = AuditLogService::withoutModelAuditing(fn () => Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']));
        $walkIn = GCashTransaction::factory()->forNonMember()->create(['status' => 'pending', 'branch_id' => $this->quiet->id]);
        $memberRow = GCashTransaction::factory()->create(['status' => 'pending', 'borrower_id' => $member->id]);
        GCashTransaction::factory()->create(['status' => 'paid', 'borrower_id' => $member->id]);
        GCashTransaction::factory()->create(['status' => 'pending', 'type' => 'cash_out', 'borrower_id' => $member->id]);
        GCashTransaction::factory()->create(['status' => 'pending', 'borrower_id' => $elsewhere->id]);
        GCashTransaction::factory()->forNonMember()->create(['status' => 'pending', 'branch_id' => $this->branch->id]);

        $item = collect($this->getJson("/api/branches/{$this->quiet->id}/closure")->assertOk()->json('data.checklist'))->firstWhere('key', 'pending_gcash_cash_ins');
        $this->assertSame(['key' => 'pending_gcash_cash_ins', 'label' => 'GCash Cash Ins not yet paid', 'count' => 2, 'amount' => null, 'clear' => false], $item);

        $this->postJson("/api/branches/{$this->quiet->id}/closure", ['effective_on' => now()->toDateString(), 'reason' => 'Closing'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', "This branch can't be closed yet: 2 GCash Cash Ins not yet paid.");

        $walkIn->update(['status' => 'paid', 'paid_at' => now()]);
        $memberRow->update(['status' => 'paid', 'paid_at' => now()]);
        $this->requestClosure();
        $late = GCashTransaction::factory()->create(['status' => 'pending', 'borrower_id' => $member->id]);

        $this->actingAs($this->checker);
        $this->postJson("/api/branches/{$this->quiet->id}/closure/approve")
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', "This branch can't be closed yet: 1 GCash Cash In not yet paid.");

        $late->update(['status' => 'paid', 'paid_at' => now()]);
        $this->postJson("/api/branches/{$this->quiet->id}/closure/approve")->assertOk();
        $this->assertSame('closed', $this->quiet->fresh()->status);
    }

    /**
     * An expense recorded as owed is paid into its own branch, which a closed
     * branch refuses, so a closure waits for the branch's unpaid and partly
     * paid payables (finish run round 2, review of #369).
     */
    public function test_closing_waits_for_the_branchs_unpaid_payables(): void
    {
        $this->seedChartOfAccounts();
        $unpaid = AccountingExpense::factory()->create(['branch_id' => $this->quiet->id]);
        $partly = AccountingExpense::factory()->create(['branch_id' => $this->quiet->id, 'amount_paid' => 500, 'settlement_status' => 'partially_paid']);
        AccountingExpense::factory()->create(['branch_id' => $this->quiet->id, 'amount_paid' => 1500050, 'settlement_status' => 'paid']);
        AccountingExpense::factory()->create(['branch_id' => $this->branch->id]);

        $item = collect($this->getJson("/api/branches/{$this->quiet->id}/closure")->assertOk()->json('data.checklist'))->firstWhere('key', 'unpaid_payables');
        $this->assertSame(['key' => 'unpaid_payables', 'label' => 'Unpaid payables', 'count' => 2, 'amount' => null, 'clear' => false], $item);

        $this->postJson("/api/branches/{$this->quiet->id}/closure", ['effective_on' => now()->toDateString(), 'reason' => 'Closing'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', "This branch can't be closed yet: 2 unpaid payables.");

        $unpaid->forceFill(['amount_paid' => 1500050, 'settlement_status' => 'paid'])->save();
        $partly->forceFill(['amount_paid' => 1500050, 'settlement_status' => 'paid'])->save();
        $this->requestClosure();
    }

    /**
     * Every share capital auto-credit run credits these members in the branch,
     * and a run refuses a closed or closing member branch whole, so a closure
     * waits until their auto-credit is off or they've moved (finish run round
     * 2, review of #378). A pledge with no amount, a member whose membership
     * ended and another branch's members don't count: a run credits none.
     */
    public function test_closing_waits_for_members_with_share_capital_auto_credit_on(): void
    {
        $member = fn (int $branchId, string $status = 'active'): Borrower => AuditLogService::withoutModelAuditing(
            fn () => Borrower::factory()->create(['branch_id' => $branchId, 'status' => $status]),
        );
        $on = [$member($this->quiet->id), $member($this->quiet->id)];

        foreach ($on as $credited) {
            $credited->shareCapitalPledge->update(['auto_credit' => true, 'amount' => 500]);
        }

        $member($this->quiet->id)->shareCapitalPledge->update(['auto_credit' => true, 'amount' => 0]);
        $member($this->quiet->id, 'withdrawn')->shareCapitalPledge->update(['auto_credit' => true, 'amount' => 500]);
        $member($this->branch->id)->shareCapitalPledge->update(['auto_credit' => true, 'amount' => 500]);
        $member($this->quiet->id);

        $item = collect($this->getJson("/api/branches/{$this->quiet->id}/closure")->assertOk()->json('data.checklist'))->firstWhere('key', 'auto_credit_members');
        $this->assertSame(['key' => 'auto_credit_members', 'label' => 'Members with share capital auto-credit on', 'count' => 2, 'amount' => null, 'clear' => false], $item);

        $this->postJson("/api/branches/{$this->quiet->id}/closure", ['effective_on' => now()->toDateString(), 'reason' => 'Closing'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', "This branch can't be closed yet: 2 members with share capital auto-credit on.");

        $on[0]->shareCapitalPledge->update(['auto_credit' => false]);
        $this->postJson("/api/branches/{$this->quiet->id}/closure", ['effective_on' => now()->toDateString(), 'reason' => 'Closing'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', "This branch can't be closed yet: 1 member with share capital auto-credit on.");

        $on[1]->shareCapitalPledge->update(['auto_credit' => false]);
        $this->requestClosure();
    }

    /**
     * An accrual whose journal was reversed by hand on the Journals screen
     * keeps its unpaid status, but nothing is owed in the books, so it doesn't
     * hold the closure (re-review of #369).
     */
    public function test_an_accrual_whose_journal_was_reversed_does_not_hold_the_closure(): void
    {
        $this->seedChartOfAccounts();
        $id = $this->postJson('/api/accounting/expenses', [
            'date' => now()->toDateString(), 'payee' => 'Meralco', 'expense_account_id' => $this->account('5030'),
            'amount' => 150000, 'branch_id' => $this->quiet->id,
        ])->assertCreated()->json('data.id');
        $expense = AccountingExpense::query()->findOrFail($id);
        $this->assertSame('unpaid', $expense->settlement_status);

        $this->postJson("/api/accounting/journals/{$expense->journal_id}/reverse")->assertCreated();

        $item = collect($this->getJson("/api/branches/{$this->quiet->id}/closure")->assertOk()->json('data.checklist'))->firstWhere('key', 'unpaid_payables');
        $this->assertSame(['key' => 'unpaid_payables', 'label' => 'Unpaid payables', 'count' => 0, 'amount' => null, 'clear' => true], $item);
        $this->assertSame('unpaid', $expense->refresh()->settlement_status);
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

        $this->assertSame('suspended', $this->quiet->fresh()->status);
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
        $this->patchStatus('active', 'Reopened')->assertOk();
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

        $this->assertSame('suspended', $this->quiet->fresh()->status);
        $this->postJson("/api/branches/{$this->quiet->id}/closure/approve")
            ->assertNotFound()
            ->assertJsonPath('message', 'No request to close this branch is waiting.');
        $this->assertSame(
            ['branch_closure_requested', 'branch_closure_rejected', 'branch_closure_requested', 'branch_closure_cancelled'],
            $this->auditRows($this->quiet->id)->pluck('action')->all(),
        );
    }

    public function test_an_active_branch_s_closure_request_is_refused_until_it_is_suspended(): void
    {
        $active = Branch::factory()->create(['name' => 'Busy Branch', 'code' => 'BUS']);

        $this->postJson("/api/branches/{$active->id}/closure", ['effective_on' => now()->toDateString(), 'reason' => 'Closing'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', 'This branch is still active. Suspend it before asking to close it.');

        $this->assertFalse(BranchClosure::query()->where('branch_id', $active->id)->exists());
        $this->assertFalse($this->getJson("/api/branches/{$active->id}/closure")->assertOk()->json('data.can_close'));
    }

    public function test_reactivation_is_refused_while_a_request_waits_and_goes_ahead_after_a_cancel(): void
    {
        $this->requestClosure();

        $this->patchStatus('active', 'Back in business')
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', 'A request to close this branch is waiting. Reject or cancel it before reactivating the branch.');
        $this->assertSame('suspended', $this->quiet->fresh()->status);
        $this->assertSame('pending', BranchClosure::query()->where('branch_id', $this->quiet->id)->sole()->status);

        $this->deleteJson("/api/branches/{$this->quiet->id}/closure")->assertOk();
        $this->patchStatus('active', 'Back in business')->assertOk();
        $this->assertSame('active', $this->quiet->fresh()->status);
    }

    public function test_approval_re_checks_the_status_under_the_lock(): void
    {
        $this->requestClosure();
        // A status set outside the API, past the reactivation guard.
        Branch::query()->whereKey($this->quiet->id)->update(['status' => 'active']);

        $this->actingAs($this->checker);
        $this->postJson("/api/branches/{$this->quiet->id}/closure/approve")
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', 'This branch is still active. Suspend it before asking to close it.');

        $this->assertSame('active', $this->quiet->fresh()->status);
        $this->assertSame('pending', BranchClosure::query()->where('branch_id', $this->quiet->id)->sole()->status);
    }

    public function test_a_branch_suspended_through_its_status_then_closes(): void
    {
        $branch = Branch::factory()->create(['name' => 'Old Branch', 'code' => 'OLD']);
        $this->quiet = $branch;

        $this->patchStatus('suspended', 'Winding down')->assertOk();
        $this->assertTrue($this->getJson("/api/branches/{$branch->id}/closure")->assertOk()->json('data.can_close'));
        $this->requestClosure();

        $this->actingAs($this->checker);
        $this->postJson("/api/branches/{$branch->id}/closure/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame('closed', $branch->fresh()->status);
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

    private function patchStatus(string $status, string $reason): TestResponse
    {
        return $this->patchJson("/api/branches/{$this->quiet->id}/status", ['status' => $status, 'reason' => $reason]);
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
