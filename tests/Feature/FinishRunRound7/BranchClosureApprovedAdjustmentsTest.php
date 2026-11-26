<?php

namespace Tests\Feature\FinishRunRound7;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BranchAccess;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 7: an approved loan adjustment that isn't applied yet can
 * still be cancelled or applied (round 6), so it keeps a branch open as a
 * pending one does. The checklist counts both on one line, in words, and a
 * closure waits for them. Applied, rejected and cancelled ones don't count,
 * nor do another branch's.
 */
class BranchClosureApprovedAdjustmentsTest extends TestCase
{
    use SetupLendyPH;

    private Branch $quiet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->quiet = Branch::factory()->create(['name' => 'Quiet Branch', 'code' => 'QUI', 'status' => 'suspended']);
        $checker = User::factory()->inBranches($this->branch)->create();
        $checker->givePermissionTo(['branches:manage', BranchAccess::VIEW_ALL]);
    }

    public function test_approved_adjustments_not_yet_applied_keep_the_branch_open_beside_pending_ones(): void
    {
        $member = AuditLogService::withoutModelAuditing(fn () => Borrower::factory()->create(['branch_id' => $this->quiet->id, 'status' => 'active']));
        // A completed loan: only the adjustments hold the branch.
        $loan = Loan::factory()->create(['borrower_id' => $member->id, 'branch_id' => $this->quiet->id, 'status' => 'completed']);
        LoanAdjustment::factory()->create(['loan_id' => $loan->id, 'status' => 'approved']);
        LoanAdjustment::factory()->create(['loan_id' => $loan->id, 'status' => 'approved']);
        LoanAdjustment::factory()->create(['loan_id' => $loan->id, 'status' => 'pending']);
        foreach (['applied', 'rejected', 'cancelled'] as $done) {
            LoanAdjustment::factory()->create(['loan_id' => $loan->id, 'status' => $done]);
        }
        $elsewhere = Loan::factory()->create(['branch_id' => $this->branch->id, 'status' => 'completed']);
        LoanAdjustment::factory()->create(['loan_id' => $elsewhere->id, 'status' => 'approved']);

        $data = $this->getJson("/api/branches/{$this->quiet->id}/closure")->assertOk()->json('data');
        $line = collect($data['checklist'])->firstWhere('key', 'loan_adjustments');

        $this->assertSame(
            ['key' => 'loan_adjustments', 'label' => 'Loan adjustments to approve, cancel or apply', 'count' => 3, 'amount' => null, 'clear' => false],
            $line,
        );
        $this->assertSame(0, collect($data['checklist'])->firstWhere('key', 'pending_approvals')['count'], 'counted once, on its own line');
        $this->assertFalse($data['can_close']);

        $this->postJson("/api/branches/{$this->quiet->id}/closure", ['effective_on' => now()->toDateString(), 'reason' => 'Merged'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', "This branch can't be closed yet: 3 loan adjustments to approve, cancel or apply.");
    }

    public function test_one_approved_adjustment_alone_holds_the_closure(): void
    {
        $member = AuditLogService::withoutModelAuditing(fn () => Borrower::factory()->create(['branch_id' => $this->quiet->id, 'status' => 'active']));
        $loan = Loan::factory()->create(['borrower_id' => $member->id, 'branch_id' => $this->quiet->id, 'status' => 'completed']);
        $adjustment = LoanAdjustment::factory()->create(['loan_id' => $loan->id, 'status' => 'approved']);

        $this->postJson("/api/branches/{$this->quiet->id}/closure", ['effective_on' => now()->toDateString(), 'reason' => 'Merged'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', "This branch can't be closed yet: 1 loan adjustment to approve, cancel or apply.");

        // Cancelled (round 6), it no longer holds the branch.
        $adjustment->forceFill(['status' => 'cancelled'])->saveQuietly();

        $this->postJson("/api/branches/{$this->quiet->id}/closure", ['effective_on' => now()->toDateString(), 'reason' => 'Merged'])
            ->assertCreated();
    }
}
