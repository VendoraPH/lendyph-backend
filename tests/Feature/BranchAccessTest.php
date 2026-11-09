<?php

namespace Tests\Feature;

use App\Models\AccountingExpense;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Collateral;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\ShareCapitalLedger;
use App\Models\ShareCapitalPledge;
use App\Models\User;
use App\Services\BranchAccess;
use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Branch access, decision 5b (2026-11-02), least privilege.
 *
 * A user without `branches:view_all` sees only the rows of the branches they
 * are assigned to (the `branch_user` pivot, falling back to the legacy
 * `users.branch_id`). A record in another branch answers 404. A user with no
 * branch and no `view_all` sees nothing. Jobs and commands are never scoped,
 * and nothing is done with a global scope.
 *
 * Replaces BranchAssignmentIsDisplayOnlyTest, whose intent this decision
 * reverses.
 */
class BranchAccessTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
    }

    // -----------------------------------------------------------------
    // Loans
    // -----------------------------------------------------------------

    public function test_a_branch_user_sees_only_their_branch_in_the_loans_list_with_stats_and_totals_scoped(): void
    {
        $here = $this->createReleasedLoan();
        $there = $this->moveToFarBranch($this->createReleasedLoan());
        $draftThere = $this->draftLoanIn($this->far);

        // The admin's view of Main Branch alone, through the filter that
        // already existed: what the branch user must now see by default.
        $expected = $this->getJson("/api/loans?branch_id={$this->branch->id}")->assertOk();

        $this->actingAs($this->branchUser());
        $response = $this->getJson('/api/loans')->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($here->id, $ids);
        $this->assertNotContains($there->id, $ids);
        $this->assertNotContains($draftThere->id, $ids);

        $this->assertSame($expected->json('meta.stats'), $response->json('meta.stats'));
        $this->assertSame($expected->json('meta.totals'), $response->json('meta.totals'));
        $this->assertSame($expected->json('meta.total'), $response->json('meta.total'));

        // And the scope really narrowed something: the whole book is larger.
        $this->actingAs($this->admin);
        $whole = $this->getJson('/api/loans')->assertOk();
        $this->assertGreaterThan($response->json('meta.total'), $whole->json('meta.total'));
        $this->assertGreaterThan(
            $response->json('meta.totals.total_outstanding'),
            $whole->json('meta.totals.total_outstanding'),
        );
    }

    public function test_another_branchs_loan_answers_404_on_show_child_reads_and_writes(): void
    {
        $there = $this->moveToFarBranch($this->createReleasedLoan());
        $draftThere = $this->draftLoanIn($this->far);

        $this->actingAs($this->branchUser());

        $this->getJson("/api/loans/{$there->id}")->assertNotFound();
        $this->getJson("/api/loans/{$there->id}/amortization-schedule")->assertNotFound();
        $this->getJson("/api/loans/{$there->id}/collaterals")->assertNotFound();
        // Served by other controllers: the binding scopes them all.
        $this->getJson("/api/loans/{$there->id}/repayments")->assertNotFound();
        $this->getJson("/api/loans/{$there->id}/summary")->assertNotFound();
        $this->getJson("/api/loans/{$there->id}/ledger")->assertNotFound();
        $this->patchJson("/api/loans/{$draftThere->id}/submit")->assertNotFound();

        $this->assertSame('draft', $draftThere->fresh()->status);
    }

    public function test_a_branch_user_still_reads_their_own_branchs_loan(): void
    {
        $here = $this->createReleasedLoan();

        $this->actingAs($this->branchUser());

        $this->getJson("/api/loans/{$here->id}")->assertOk()->assertJsonPath('data.id', $here->id);
        $this->getJson("/api/loans/{$here->id}/amortization-schedule")->assertOk();
    }

    public function test_a_loan_cannot_be_created_for_another_branchs_borrower(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->far->id, 'status' => 'active']);
        $product = LoanProduct::factory()->create();

        $this->actingAs($this->branchUser());

        $this->postJson('/api/loans', [
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'start_date' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('borrower_id');

        $this->assertSame(0, Loan::where('borrower_id', $borrower->id)->count());
    }

    // -----------------------------------------------------------------
    // Borrowers
    // -----------------------------------------------------------------

    public function test_a_branch_user_sees_only_their_branch_in_the_borrowers_list_with_stats_scoped(): void
    {
        $here = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $there = Borrower::factory()->create(['branch_id' => $this->far->id]);
        $unassigned = Borrower::factory()->create(['branch_id' => null]);

        $expected = $this->getJson("/api/borrowers?branch_id={$this->branch->id}")->assertOk();

        $this->actingAs($this->branchUser());
        $response = $this->getJson('/api/borrowers?per_page=100')->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($here->id, $ids);
        $this->assertNotContains($there->id, $ids);
        $this->assertNotContains($unassigned->id, $ids);
        $this->assertSame($expected->json('meta.stats'), $response->json('meta.stats'));
        $this->assertSame($expected->json('meta.total'), $response->json('meta.total'));
    }

    public function test_another_branchs_borrower_answers_404_on_show_ledger_and_loan_summary(): void
    {
        $there = Borrower::factory()->create(['branch_id' => $this->far->id]);

        $this->actingAs($this->branchUser());

        $this->getJson("/api/borrowers/{$there->id}")->assertNotFound();
        $this->getJson("/api/borrowers/{$there->id}/ledger")->assertNotFound();
        $this->getJson("/api/borrowers/{$there->id}/loan-summary")->assertNotFound();
        $this->getJson("/api/borrowers/{$there->id}/valid-ids")->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Who sees everything, and who sees nothing
    // -----------------------------------------------------------------

    public function test_view_all_sees_every_branch(): void
    {
        $there = $this->moveToFarBranch($this->createReleasedLoan());
        $borrowerThere = Borrower::factory()->create(['branch_id' => $this->far->id]);

        $manager = User::factory()->inBranches($this->branch)->create();
        $manager->assignRole('manager');
        $manager->givePermissionTo('borrowers:view');

        $this->actingAs($manager);

        $this->assertContains($there->id, array_column($this->getJson('/api/loans?per_page=100')->assertOk()->json('data'), 'id'));
        $this->getJson("/api/loans/{$there->id}")->assertOk();
        $this->getJson("/api/borrowers/{$borrowerThere->id}")->assertOk();
        $this->getJson("/api/loans?branch_id={$this->far->id}")->assertOk();
    }

    public function test_a_user_with_no_branch_and_no_view_all_sees_nothing(): void
    {
        $here = $this->createReleasedLoan();

        $nobody = User::factory()->create(['branch_id' => null]);
        $nobody->assignRole('loan_officer');
        $this->assertSame(0, $nobody->branches()->count());

        $this->actingAs($nobody);

        $loans = $this->getJson('/api/loans')->assertOk();
        $this->assertSame([], $loans->json('data'));
        $this->assertSame(0, $loans->json('meta.stats.active'));
        $this->assertEquals(0, $loans->json('meta.totals.total_outstanding'));

        $this->assertSame([], $this->getJson('/api/borrowers')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/collaterals')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/pledges')->assertOk()->json('data'));
        $this->getJson("/api/loans/{$here->id}")->assertNotFound();
        $this->getJson("/api/borrowers/{$here->borrower_id}")->assertNotFound();
    }

    public function test_the_legacy_branch_id_column_is_the_fallback_when_the_user_has_no_pivot_rows(): void
    {
        $here = $this->createReleasedLoan();
        $there = $this->moveToFarBranch($this->createReleasedLoan());

        $legacy = $this->branchUser();
        $legacy->branches()->detach();
        $this->assertSame($this->branch->id, (int) $legacy->branch_id);

        $this->actingAs($legacy);

        $ids = array_column($this->getJson('/api/loans')->assertOk()->json('data'), 'id');
        $this->assertContains($here->id, $ids);
        $this->assertNotContains($there->id, $ids);
        $this->getJson("/api/loans/{$there->id}")->assertNotFound();
    }

    public function test_a_user_in_several_branches_sees_all_of_them(): void
    {
        $here = $this->createReleasedLoan();
        $there = $this->moveToFarBranch($this->createReleasedLoan());

        $both = User::factory()->inBranches($this->branch, $this->far)->create();
        $both->assignRole('loan_officer');

        $this->actingAs($both);

        $ids = array_column($this->getJson('/api/loans')->assertOk()->json('data'), 'id');
        $this->assertContains($here->id, $ids);
        $this->assertContains($there->id, $ids);
    }

    // -----------------------------------------------------------------
    // The branch_id filter
    // -----------------------------------------------------------------

    public function test_a_branch_id_filter_outside_the_callers_branches_answers_403_and_inside_narrows(): void
    {
        $user = $this->branchUser();
        $user->givePermissionTo('users:view');

        $this->actingAs($user);

        $this->getJson("/api/loans?branch_id={$this->far->id}")->assertForbidden();
        $this->getJson("/api/borrowers?branch_id={$this->far->id}")->assertForbidden();
        $this->getJson("/api/users?branch_id={$this->far->id}")->assertForbidden();

        $this->getJson("/api/loans?branch_id={$this->branch->id}")->assertOk();
        $this->getJson("/api/borrowers?branch_id={$this->branch->id}")->assertOk();
        $this->getJson("/api/users?branch_id={$this->branch->id}")->assertOk();
    }

    // -----------------------------------------------------------------
    // Users and staff
    // -----------------------------------------------------------------

    public function test_the_users_and_staff_lists_show_only_users_sharing_a_branch(): void
    {
        $colleague = User::factory()->inBranches($this->branch)->create();
        $colleague->assignRole('viewer');
        $elsewhere = User::factory()->inBranches($this->far)->create();
        $elsewhere->assignRole('viewer');
        $bridging = User::factory()->inBranches($this->far, $this->branch)->create();
        $bridging->assignRole('viewer');

        $user = $this->branchUser();
        $user->givePermissionTo('users:view');

        $this->actingAs($user);

        $users = array_column($this->getJson('/api/users?per_page=100')->assertOk()->json('data'), 'id');
        $this->assertContains($colleague->id, $users);
        $this->assertContains($bridging->id, $users);
        $this->assertNotContains($elsewhere->id, $users);

        $staff = array_column($this->getJson('/api/staff?per_page=100')->assertOk()->json('data'), 'id');
        $this->assertContains($colleague->id, $staff);
        $this->assertNotContains($elsewhere->id, $staff);

        $this->actingAs($this->admin);
        $all = array_column($this->getJson('/api/users?per_page=100')->assertOk()->json('data'), 'id');
        $this->assertContains($elsewhere->id, $all);
    }

    // -----------------------------------------------------------------
    // Collateral and share capital (by the borrower's branch)
    // -----------------------------------------------------------------

    public function test_collateral_is_scoped_by_the_borrowers_branch(): void
    {
        $here = Collateral::factory()->create([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
        ]);
        $there = Collateral::factory()->create([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->far->id])->id,
        ]);

        $this->actingAs($this->branchUser());

        $ids = array_column($this->getJson('/api/collaterals')->assertOk()->json('data'), 'id');
        $this->assertContains($here->id, $ids);
        $this->assertNotContains($there->id, $ids);

        $register = $this->getJson('/api/collaterals/register?per_page=100')->assertOk();
        $members = array_column($register->json('data'), 'borrower_id');
        $this->assertContains($here->borrower_id, $members);
        $this->assertNotContains($there->borrower_id, $members);
        $this->assertSame(
            Collateral::whereHas('borrower', fn ($q) => $q->where('branch_id', $this->branch->id))->count(),
            $register->json('meta.totals.total_collaterals'),
        );

        $this->getJson("/api/collaterals/{$here->id}")->assertOk();
        $this->getJson("/api/collaterals/{$there->id}")->assertNotFound();

        $this->postJson('/api/collaterals', [
            'borrower_id' => $there->borrower_id,
            'collateral_type_id' => $there->collateral_type_id,
            'amount' => 1000,
        ])->assertUnprocessable()->assertJsonValidationErrors('borrower_id');
    }

    public function test_share_capital_ledger_members_and_pledges_are_scoped_by_the_borrowers_branch(): void
    {
        $here = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $there = Borrower::factory()->create(['branch_id' => $this->far->id, 'status' => 'active']);
        ShareCapitalLedger::factory()->create(['borrower_id' => $here->id, 'credit' => 1000]);
        ShareCapitalLedger::factory()->create(['borrower_id' => $there->id, 'credit' => 5000]);
        $pledgeThere = ShareCapitalPledge::where('borrower_id', $there->id)->firstOrFail();

        $this->actingAs($this->branchUser());

        $ledger = array_column($this->getJson('/api/share-capital/ledger?per_page=100')->assertOk()->json('data'), 'borrower_id');
        $this->assertContains($here->id, $ledger);
        $this->assertNotContains($there->id, $ledger);

        $members = $this->getJson('/api/share-capital/members?per_page=100')->assertOk();
        $memberIds = array_column($members->json('data'), 'borrower_id');
        $this->assertContains($here->id, $memberIds);
        $this->assertNotContains($there->id, $memberIds);
        $this->assertSame(
            DB::table('share_capital_ledger')->join('borrowers', 'borrowers.id', '=', 'share_capital_ledger.borrower_id')
                ->where('borrowers.branch_id', $this->branch->id)->distinct()->count('share_capital_ledger.borrower_id'),
            $members->json('meta.totals.member_count'),
        );

        $pledges = array_column($this->getJson('/api/pledges?per_page=100')->assertOk()->json('data'), 'borrower_id');
        $this->assertContains($here->id, $pledges);
        $this->assertNotContains($there->id, $pledges);

        $this->patchJson("/api/pledges/{$pledgeThere->id}/auto-credit")->assertNotFound();
        $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $there->id,
            'date' => now()->toDateString(),
            'description' => 'Probe',
            'type' => 'credit',
            'amount' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('borrower_id');

        $this->assertSame(1, ShareCapitalLedger::where('borrower_id', $there->id)->count());
    }

    // -----------------------------------------------------------------
    // Accounting (by the row's own branch; null rows only for view_all)
    // -----------------------------------------------------------------

    public function test_expenses_are_scoped_by_their_own_branch_and_unbranched_rows_need_view_all(): void
    {
        $this->seedChartOfAccounts();

        $here = AccountingExpense::factory()->create(['branch_id' => $this->branch->id]);
        $there = AccountingExpense::factory()->create(['branch_id' => $this->far->id]);
        $unbranched = AccountingExpense::factory()->create(['branch_id' => null]);

        $user = $this->branchUser();
        $user->givePermissionTo('expenses:view');

        $this->actingAs($user);

        $ids = array_column($this->getJson('/api/accounting/expenses?per_page=100')->assertOk()->json('data'), 'id');
        $this->assertContains($here->id, $ids);
        $this->assertNotContains($there->id, $ids);
        $this->assertNotContains($unbranched->id, $ids);
        $this->getJson("/api/accounting/expenses/{$there->id}")->assertNotFound();
        $this->getJson("/api/accounting/expenses?branch_id={$this->far->id}")->assertForbidden();

        $this->actingAs($this->admin);
        $all = array_column($this->getJson('/api/accounting/expenses?per_page=100')->assertOk()->json('data'), 'id');
        $this->assertContains($there->id, $all);
        $this->assertContains($unbranched->id, $all);
    }

    // -----------------------------------------------------------------
    // Jobs, commands and structure
    // -----------------------------------------------------------------

    public function test_commands_are_never_scoped_even_with_a_restricted_user_signed_in(): void
    {
        $there = $this->moveToFarBranch($this->createReleasedLoan([
            'start_date' => now()->subYear()->toDateString(),
        ]));

        $nobody = User::factory()->create(['branch_id' => null]);
        $nobody->assignRole('loan_officer');
        $this->actingAs($nobody);

        $this->artisan('loans:check-defaulted')->assertSuccessful();

        $this->assertSame('defaulted', $there->fresh()->status);
    }

    public function test_no_model_declares_a_global_scope(): void
    {
        $offenders = [];

        foreach ($this->modelClasses() as $class) {
            $scopes = array_values(array_diff(array_keys((new $class)->getGlobalScopes()), [SoftDeletingScope::class]));

            if ($scopes !== []) {
                $offenders[$class] = $scopes;
            }
        }

        $this->assertSame([], $offenders, 'Branch access is local scopes only; a global scope would also scope jobs, commands and reports.');
    }

    // -----------------------------------------------------------------
    // The permission
    // -----------------------------------------------------------------

    public function test_view_all_is_seeded_for_the_oversight_roles_only(): void
    {
        $holders = Role::whereHas('permissions', fn ($q) => $q->where('name', BranchAccess::VIEW_ALL))
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            ['accountant', 'admin', 'bod1', 'bod2', 'bod3', 'bod4', 'bod5', 'bod6', 'bod7', 'general_bookkeeper', 'manager', 'super_admin'],
            $holders,
        );
    }

    public function test_the_migration_grants_view_all_to_every_board_role_and_is_idempotent(): void
    {
        $chair = Role::create(['name' => 'bod_chairwoman', 'guard_name' => 'web']);
        $other = Role::create(['name' => 'it_head', 'guard_name' => 'web']);

        $migration = require database_path('migrations/2026_11_02_210000_add_branches_view_all_permission.php');
        $migration->up();
        $migration->up();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($chair->fresh()->hasPermissionTo(BranchAccess::VIEW_ALL));
        $this->assertFalse($other->fresh()->hasPermissionTo(BranchAccess::VIEW_ALL));
        $this->assertSame(1, DB::table('permissions')->where('name', BranchAccess::VIEW_ALL)->count());

        $permissionId = DB::table('permissions')->where('name', BranchAccess::VIEW_ALL)->value('id');
        $this->assertSame(1, DB::table('role_has_permissions')->where('permission_id', $permissionId)->where('role_id', $chair->id)->count());
    }

    // -----------------------------------------------------------------

    /**
     * A loan officer (no `branches:view_all`) assigned to Main Branch only.
     */
    private function branchUser(): User
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole('loan_officer');

        return $user;
    }

    private function moveToFarBranch(Loan $loan): Loan
    {
        $loan->borrower->update(['branch_id' => $this->far->id]);
        $loan->update(['branch_id' => $this->far->id]);

        return $loan->fresh();
    }

    private function draftLoanIn(Branch $branch): Loan
    {
        return Loan::factory()->create([
            'branch_id' => $branch->id,
            'borrower_id' => Borrower::factory()->create(['branch_id' => $branch->id]),
            'loan_product_id' => LoanProduct::factory(),
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * @return list<class-string<Model>>
     */
    private function modelClasses(): array
    {
        $classes = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path('Models'), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(app_path('Models').'/', '', $file->getPathname());
            $class = 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $relative);

            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}
