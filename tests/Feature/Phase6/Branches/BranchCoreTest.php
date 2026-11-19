<?php

namespace Tests\Feature\Phase6\Branches;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\PortfolioOutstanding;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE1 (multi-branch decisions 2 and 4): a branch's profile, its
 * status (active, suspended or closed) with `is_active` derived from it, the
 * main-office flag, and its figures. Status and main-office changes need
 * `branches:manage` plus `branches:view_all`.
 */
class BranchCoreTest extends TestCase
{
    use SetupLendyPH;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->second = Branch::factory()->create(['name' => 'Second Branch', 'code' => 'SEC']);
    }

    // Status and is_active ------------------------------------------------

    public function test_an_inactive_branch_reads_suspended_and_is_active_follows_the_status(): void
    {
        $branch = Branch::factory()->create(['is_active' => false]);

        $this->assertSame('suspended', $branch->status);
        $this->assertFalse($branch->is_active);
        $this->assertSame(0, (int) DB::table('branches')->where('id', $branch->id)->value('is_active'));

        DB::table('branches')->where('id', $branch->id)->update(['status' => 'active']);
        $this->assertSame(1, (int) DB::table('branches')->where('id', $branch->id)->value('is_active'));
        $this->assertTrue($branch->fresh()->is_active);

        DB::table('branches')->where('id', $branch->id)->update(['status' => 'closed']);
        $this->assertFalse($branch->fresh()->is_active);
    }

    public function test_the_public_list_and_the_active_picker_leave_out_suspended_and_closed_branches(): void
    {
        $suspended = Branch::factory()->create(['name' => 'Suspended Branch', 'status' => 'suspended']);
        $closed = Branch::factory()->create(['name' => 'Closed Branch', 'status' => 'closed']);

        $public = collect($this->getJson('/api/branches/public')->assertOk()->json('data'))->pluck('id');
        $picker = collect($this->getJson('/api/branches?active_only=1')->assertOk()->json('data'))->pluck('id');

        foreach ([$public, $picker] as $ids) {
            $this->assertContains($this->second->id, $ids);
            $this->assertNotContains($suspended->id, $ids);
            $this->assertNotContains($closed->id, $ids);
        }
    }

    public function test_a_new_branch_is_active_and_not_the_main_office(): void
    {
        $data = $this->postJson('/api/branches', ['name' => 'Butuan Branch', 'code' => 'BTN', 'status' => 'closed', 'is_main_office' => true])
            ->assertCreated()
            ->json('data');

        $this->assertSame('active', $data['status']);
        $this->assertTrue($data['is_active']);
        $this->assertFalse($data['is_main_office']);
    }

    // Profile ---------------------------------------------------------------

    public function test_the_profile_fields_save_show_and_are_audited_in_words(): void
    {
        $manager = User::factory()->inBranches($this->second)->create(['first_name' => 'Maria', 'last_name' => 'Santos']);

        $this->putJson("/api/branches/{$this->second->id}", [
            'email' => 'second@example.test',
            'manager_user_id' => $manager->id,
            'opened_on' => '2024-02-01',
            'cda_authority_number' => 'CDA-BR-0001',
            'cda_authority_issued_on' => '2024-01-15',
        ])->assertOk();

        $data = $this->getJson("/api/branches/{$this->second->id}")->assertOk()->json('data');

        $this->assertSame('second@example.test', $data['email']);
        $this->assertSame(['id' => $manager->id, 'full_name' => 'Maria Santos'], $data['manager']);
        $this->assertSame('2024-02-01', $data['opened_on']);
        $this->assertNull($data['closed_on']);
        $this->assertSame('CDA-BR-0001', $data['cda_authority_number']);
        $this->assertSame('2024-01-15', $data['cda_authority_issued_on']);
        $this->assertTrue($data['cda_authority_to_verify']);

        $row = $this->auditRows($this->second->id)->where('action', 'updated')->sole();
        $this->assertSame(
            'Branch "Second Branch" (SEC) updated: email, manager, opening date, CDA authority number, CDA authority issue date.',
            $row->description,
        );
    }

    public function test_a_manager_must_be_an_active_user(): void
    {
        $inactive = User::factory()->inactive()->create();

        $this->putJson("/api/branches/{$this->second->id}", ['manager_user_id' => $inactive->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('manager_user_id');
    }

    public function test_status_closing_date_and_main_office_are_not_edited_through_the_profile(): void
    {
        $this->putJson("/api/branches/{$this->second->id}", ['status' => 'closed', 'closed_on' => '2026-01-01', 'is_main_office' => true])->assertOk();

        $fresh = $this->second->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->closed_on);
        $this->assertFalse($fresh->is_main_office);
    }

    public function test_switching_is_active_on_the_profile_needs_the_branch_management_permission(): void
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->givePermissionTo(['settings:view', 'settings:update', BranchAccess::VIEW_ALL]);
        $this->actingAs($user);

        $this->putJson("/api/branches/{$this->second->id}", ['is_active' => false])
            ->assertForbidden()
            ->assertJsonPath('message', "Changing a branch's status needs the branch management permission and access to all branches.");
        $this->assertSame('active', $this->second->fresh()->status);

        // An unchanged value is still accepted, so the edit form can send it.
        $this->putJson("/api/branches/{$this->second->id}", ['is_active' => true, 'name' => 'Renamed'])->assertOk();
        $this->assertSame('Renamed', $this->second->fresh()->name);
    }

    public function test_a_closed_branch_is_not_reopened_through_the_profile(): void
    {
        $closed = Branch::factory()->create(['status' => 'closed']);

        $this->putJson("/api/branches/{$closed->id}", ['is_active' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'A closed branch stays closed.');
    }

    // Status endpoint -------------------------------------------------------

    public function test_suspending_and_reactivating_a_branch_are_audited_with_the_reason(): void
    {
        $this->patchJson("/api/branches/{$this->second->id}/status", ['status' => 'suspended', 'reason' => 'Renovation'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.is_active', false);

        $this->patchJson("/api/branches/{$this->second->id}/status", ['status' => 'active', 'reason' => 'Reopened after renovation'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame([
            'Branch "Second Branch" (SEC) suspended. Reason: Renovation',
            'Branch "Second Branch" (SEC) reactivated. Reason: Reopened after renovation',
        ], $this->auditRows($this->second->id)->where('action', 'updated')->pluck('description')->values()->all());
    }

    public function test_the_status_endpoint_never_closes_a_branch_and_needs_a_reason(): void
    {
        $this->patchJson("/api/branches/{$this->second->id}/status", ['status' => 'closed', 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->patchJson("/api/branches/{$this->second->id}/status", ['status' => 'suspended'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_the_same_status_again_is_refused(): void
    {
        $this->patchJson("/api/branches/{$this->second->id}/status", ['status' => 'active', 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'This branch is already active.');
    }

    public function test_a_closed_branch_keeps_its_status(): void
    {
        $closed = Branch::factory()->create(['status' => 'closed']);

        $this->patchJson("/api/branches/{$closed->id}/status", ['status' => 'active', 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'A closed branch stays closed.');
    }

    public function test_the_main_office_cannot_be_suspended(): void
    {
        $this->putJson("/api/branches/{$this->second->id}/main-office")->assertOk();

        $this->patchJson("/api/branches/{$this->second->id}/status", ['status' => 'suspended', 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'The main office stays active. Make another branch the main office first.');
    }

    public function test_changing_a_status_needs_branch_management_and_view_all(): void
    {
        $message = "Changing a branch's status needs the branch management permission and access to all branches.";

        $withoutViewAll = User::factory()->inBranches($this->second)->create();
        $withoutViewAll->givePermissionTo(['branches:manage']);
        $this->actingAs($withoutViewAll);
        $this->patchJson("/api/branches/{$this->second->id}/status", ['status' => 'suspended', 'reason' => 'x'])
            ->assertForbidden()->assertJsonPath('message', $message);
        $this->patchJson("/api/branches/{$this->branch->id}/status", ['status' => 'suspended', 'reason' => 'x'])
            ->assertNotFound();

        $withoutManage = User::factory()->inBranches($this->branch)->create();
        $withoutManage->givePermissionTo(['settings:update', BranchAccess::VIEW_ALL]);
        $this->actingAs($withoutManage);
        $this->patchJson("/api/branches/{$this->second->id}/status", ['status' => 'suspended', 'reason' => 'x'])
            ->assertForbidden()->assertJsonPath('message', $message);
        $this->putJson("/api/branches/{$this->second->id}/main-office")
            ->assertForbidden()->assertJsonPath('message', $message);

        $this->assertSame('active', $this->second->fresh()->status);
    }

    // Main office ---------------------------------------------------------

    public function test_making_a_branch_the_main_office_moves_the_flag_and_audits_both(): void
    {
        $this->putJson("/api/branches/{$this->branch->id}/main-office")->assertOk()->assertJsonPath('data.is_main_office', true);
        $this->putJson("/api/branches/{$this->second->id}/main-office")->assertOk()->assertJsonPath('data.is_main_office', true);

        $this->assertFalse($this->branch->fresh()->is_main_office);
        $this->assertTrue($this->second->fresh()->is_main_office);
        $this->assertSame(1, Branch::query()->where('is_main_office', true)->count());

        $this->assertSame(
            sprintf('Branch %s is no longer the main office.', $this->branch->auditName()),
            $this->auditRows($this->branch->id)->where('action', 'updated')->last()->description,
        );
        $this->assertSame(
            'Branch "Second Branch" (SEC) made the main office.',
            $this->auditRows($this->second->id)->where('action', 'updated')->last()->description,
        );
    }

    public function test_a_suspended_branch_cannot_become_the_main_office(): void
    {
        $suspended = Branch::factory()->create(['status' => 'suspended']);

        $this->putJson("/api/branches/{$suspended->id}/main-office")
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', 'Only an active branch can be the main office.');
    }

    public function test_the_database_holds_at_most_one_main_office(): void
    {
        DB::table('branches')->where('id', $this->branch->id)->update(['is_main_office' => true]);

        $this->expectException(QueryException::class);
        DB::table('branches')->where('id', $this->second->id)->update(['is_main_office' => true]);
    }

    // Figures ---------------------------------------------------------------

    public function test_a_branch_shows_its_figures_from_the_portfolio_totals(): void
    {
        $main = $this->branch;
        $this->branch = $this->second;
        $loan = $this->createReleasedLoan();
        $this->createReleasedLoan(['product' => ['term' => 3]]);
        $this->branch = $main;
        Loan::factory()->create(['borrower_id' => $loan->borrower_id, 'branch_id' => $this->second->id, 'status' => 'draft']);

        $totals = app(PortfolioOutstanding::class)->totals([$this->second->id]);
        $this->assertGreaterThan(0, $totals['total_outstanding']);

        $figures = $this->getJson("/api/branches/{$this->second->id}")->assertOk()->json('data.figures');

        // assertEquals: JSON writes a whole-peso float as an integer.
        $this->assertEquals([
            'active_loans' => 2,
            'active_members' => 2,
            'total_outstanding' => $totals['total_outstanding'],
            'outstanding_principal' => $totals['outstanding_principal'],
            'overdue_amount' => $totals['overdue_amount'],
            'overdue_count' => $totals['overdue_count'],
        ], $figures);

        $listed = collect($this->getJson('/api/branches?include=figures')->assertOk()->json('data'))->firstWhere('id', $this->second->id);
        $this->assertSame($figures, $listed['figures']);
        $this->assertSame($this->second->id, $loan->branch_id);
    }

    public function test_the_list_leaves_figures_out_unless_asked_and_they_need_loans_view(): void
    {
        $listed = collect($this->getJson('/api/branches')->assertOk()->json('data'))->firstWhere('id', $this->second->id);
        $this->assertArrayNotHasKey('figures', $listed);

        $user = User::factory()->inBranches($this->second)->create();
        $user->givePermissionTo(['settings:view']);
        $this->actingAs($user);

        $this->assertNull($this->getJson("/api/branches/{$this->second->id}")->assertOk()->json('data.figures'));
        $listed = collect($this->getJson('/api/branches?include=figures')->assertOk()->json('data'))->firstWhere('id', $this->second->id);
        $this->assertNull($listed['figures']);
    }

    /** @return Collection<int, AuditLog> */
    private function auditRows(int $branchId): Collection
    {
        return AuditLog::query()
            ->where('auditable_type', (new Branch)->getMorphClass())
            ->where('auditable_id', $branchId)
            ->orderBy('id')
            ->get();
    }
}
