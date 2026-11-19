<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\SavingsAccount;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BranchAccess;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Collection;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 FX-2 (multi-branch audit D2, D3, D5, D11): branch records are
 * audited in words, only a whole-cooperative user edits them, and a member
 * keeps their branch while savings or a time deposit is open there.
 */
class BranchIntegrityTest extends TestCase
{
    use SetupLendyPH;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->second = Branch::factory()->create(['name' => 'Second Branch', 'code' => 'SEC']);
    }

    // D2 ---------------------------------------------------------------

    public function test_creating_a_branch_writes_an_audit_row_in_words(): void
    {
        $id = $this->postJson('/api/branches', ['name' => 'Butuan Branch', 'code' => 'BTN'])->assertCreated()->json('data.id');

        $row = $this->auditRows($id)->sole();

        $this->assertSame('created', $row->action);
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertSame('Branch "Butuan Branch" (BTN) created.', $row->description);
        $this->assertSame('BTN', $row->new_values['code']);
    }

    public function test_updating_a_branch_writes_the_changed_fields_in_words(): void
    {
        $branch = Branch::factory()->create(['name' => 'Butuan Branch', 'code' => 'BTN']);

        $this->putJson("/api/branches/{$branch->id}", ['name' => 'Butuan City Branch', 'contact_number' => '09171234567'])->assertOk();

        $row = $this->auditRows($branch->id)->where('action', 'updated')->sole();

        $this->assertSame('Branch "Butuan City Branch" (BTN) updated: name, contact number.', $row->description);
        $this->assertSame('Butuan Branch', $row->old_values['name']);
        $this->assertSame('Butuan City Branch', $row->new_values['name']);
    }

    public function test_deactivating_and_reactivating_a_branch_are_audited_in_words(): void
    {
        $branch = Branch::factory()->create(['name' => 'Butuan Branch', 'code' => 'BTN', 'is_active' => true]);

        $this->putJson("/api/branches/{$branch->id}", ['is_active' => false])->assertOk();
        $this->putJson("/api/branches/{$branch->id}", ['is_active' => true, 'code' => 'BTN2'])->assertOk();

        $rows = $this->auditRows($branch->id)->where('action', 'updated')->values();

        $this->assertSame([
            'Branch "Butuan Branch" (BTN) deactivated.',
            'Branch "Butuan Branch" (BTN2) reactivated and updated: code.',
        ], $rows->pluck('description')->all());
    }

    public function test_a_save_that_changes_nothing_writes_nothing(): void
    {
        $branch = Branch::factory()->create(['name' => 'Butuan Branch', 'code' => 'BTN']);

        $this->putJson("/api/branches/{$branch->id}", ['name' => 'Butuan Branch'])->assertOk();

        $this->assertCount(0, $this->auditRows($branch->id)->where('action', 'updated'));
    }

    public function test_the_branch_seeder_writes_no_audit_row(): void
    {
        Branch::query()->where('code', 'MAIN')->update(['code' => 'OLDMAIN']);

        $this->seed(BranchSeeder::class);

        $seeded = Branch::query()->where('code', 'MAIN')->sole();
        $this->assertCount(0, $this->auditRows($seeded->id));
    }

    public function test_the_audit_trail_names_the_branch(): void
    {
        $this->postJson('/api/branches', ['name' => 'Butuan Branch', 'code' => 'BTN'])->assertCreated();

        $row = collect($this->getJson('/api/audit-logs?per_page=50')->assertOk()->json('data'))
            ->first(fn (array $row) => ($row['target']['type'] ?? null) === 'Branch');

        $this->assertNotNull($row);
        $this->assertSame('Butuan Branch', $row['target']['label']);
        $this->assertSame('Branch "Butuan Branch" (BTN) created.', $row['description']);
    }

    // D3 ---------------------------------------------------------------

    public function test_a_branch_only_user_cannot_edit_deactivate_or_recode_their_own_branch(): void
    {
        $this->actingAs($this->branchSettingsUser());

        foreach ([['is_active' => false], ['code' => 'NEW'], ['name' => 'Renamed'], ['address' => 'Elsewhere']] as $payload) {
            $this->putJson("/api/branches/{$this->branch->id}", $payload)
                ->assertForbidden()
                ->assertJsonPath('message', 'Branches are shared by the whole cooperative, so changing one needs the settings permission and access to all branches.');
        }

        $fresh = $this->branch->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame('MAIN', $fresh->code);
    }

    public function test_a_branch_only_user_still_gets_404_for_a_branch_they_cannot_see(): void
    {
        $this->actingAs($this->branchSettingsUser());

        $this->putJson("/api/branches/{$this->second->id}", ['is_active' => false])->assertNotFound();
    }

    public function test_view_all_with_the_settings_permission_still_edits_a_branch(): void
    {
        $this->actingAs($this->branchSettingsUser([BranchAccess::VIEW_ALL]));

        $this->putJson("/api/branches/{$this->second->id}", ['is_active' => false])->assertOk();
        $this->assertFalse($this->second->fresh()->is_active);
    }

    public function test_view_all_without_the_settings_permission_still_cannot_edit(): void
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->givePermissionTo([BranchAccess::VIEW_ALL]);
        $this->actingAs($user);

        $this->putJson("/api/branches/{$this->second->id}", ['is_active' => false])->assertForbidden();
    }

    // D5 and D11 -------------------------------------------------------

    public function test_a_member_with_an_open_savings_account_keeps_their_branch(): void
    {
        foreach ([SavingsAccount::STATUS_ACTIVE, SavingsAccount::STATUS_DORMANT, SavingsAccount::STATUS_FROZEN] as $status) {
            $member = $this->member();
            SavingsAccount::factory()->create(['borrower_id' => $member->id, 'branch_id' => $this->branch->id, 'status' => $status]);

            $this->putJson("/api/borrowers/{$member->id}", ['branch_id' => $this->second->id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['branch_id' => "This member can't move to another branch while they have 1 open savings account. Loans, savings and time deposits stay in the branch where they were opened, so the member keeps that branch until these are closed."]);

            $this->assertSame($this->branch->id, (int) $member->fresh()->branch_id, "A {$status} account must block the move.");
        }
    }

    public function test_a_member_with_an_open_time_deposit_keeps_their_branch(): void
    {
        foreach (['active', 'matured'] as $status) {
            $member = $this->member();
            TimeDeposit::factory()->create(['borrower_id' => $member->id, 'status' => $status]);

            $this->putJson("/api/borrowers/{$member->id}", ['branch_id' => $this->second->id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['branch_id' => "This member can't move to another branch while they have 1 open time deposit. Loans, savings and time deposits stay in the branch where they were opened, so the member keeps that branch until these are closed."]);
        }
    }

    public function test_closed_savings_and_settled_time_deposits_do_not_block_a_move(): void
    {
        $member = $this->member();
        SavingsAccount::factory()->closed()->create(['borrower_id' => $member->id, 'branch_id' => $this->branch->id]);
        foreach (['withdrawn', 'rolled_over', 'preterminated'] as $status) {
            TimeDeposit::factory()->create(['borrower_id' => $member->id, 'status' => $status]);
        }

        $this->putJson("/api/borrowers/{$member->id}", ['branch_id' => $this->second->id])->assertOk();
        $this->assertSame($this->second->id, (int) $member->fresh()->branch_id);
    }

    public function test_the_refusal_names_every_thing_that_blocks_the_move(): void
    {
        $member = $this->member();
        $product = LoanProduct::factory()->create();
        Loan::factory()->create(['borrower_id' => $member->id, 'branch_id' => $this->branch->id, 'loan_product_id' => $product->id, 'status' => 'ongoing']);
        Loan::factory()->create(['borrower_id' => $member->id, 'branch_id' => $this->branch->id, 'loan_product_id' => $product->id, 'status' => 'for_review']);
        SavingsAccount::factory()->count(2)->create(['borrower_id' => $member->id, 'branch_id' => $this->branch->id]);
        TimeDeposit::factory()->create(['borrower_id' => $member->id]);

        $message = $this->putJson("/api/borrowers/{$member->id}", ['branch_id' => $this->second->id])
            ->assertUnprocessable()
            ->json('errors.branch_id.0');

        $this->assertSame(
            "This member can't move to another branch while they have 1 loan still being paid, 1 loan application not yet released, 2 open savings accounts and 1 open time deposit. Loans, savings and time deposits stay in the branch where they were opened, so the member keeps that branch until these are closed.",
            $message,
        );
        $this->assertStringNotContainsString('move this member', $message);
    }

    public function test_an_unchanged_branch_is_never_refused(): void
    {
        $member = $this->member();
        SavingsAccount::factory()->create(['borrower_id' => $member->id, 'branch_id' => $this->branch->id]);

        $this->putJson("/api/borrowers/{$member->id}", ['branch_id' => $this->branch->id, 'employer_or_business' => 'Sari-sari store'])->assertOk();
    }

    // Fixtures ---------------------------------------------------------

    /** @return Collection<int, AuditLog> */
    private function auditRows(int $branchId)
    {
        return AuditLog::query()
            ->where('auditable_type', (new Branch)->getMorphClass())
            ->where('auditable_id', $branchId)
            ->orderBy('id')
            ->get();
    }

    private function member(): Borrower
    {
        return AuditLogService::withoutModelAuditing(fn () => Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']));
    }

    /**
     * A settings manager in the main branch only, without `branches:view_all`
     * unless it's given.
     *
     * @param  list<string>  $extra
     */
    private function branchSettingsUser(array $extra = []): User
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->givePermissionTo(['settings:view', 'settings:update', ...$extra]);

        return $user;
    }
}
