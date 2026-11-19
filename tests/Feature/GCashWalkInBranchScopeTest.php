<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\GCashNonMember;
use App\Models\GCashTransaction;
use App\Models\Role;
use App\Models\User;
use App\Services\BranchAccess;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 FX-3 (D6, decision 1): a walk-in GCash row that carries a branch is
 * listed, shown and counted only for users who can see that branch; a walk-in
 * row with no branch (head office) needs `branches:view_all`. A member's row
 * keeps following the member's branch. The income report also counts its
 * Cash In and Cash Out rows (UI-round flag 9) in that same scope.
 */
class GCashWalkInBranchScopeTest extends TestCase
{
    use SetupLendyPH;

    private Branch $far;

    private GCashNonMember $walkInPerson;

    /** @var array<string, GCashTransaction> */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $this->walkInPerson = GCashNonMember::factory()->create();

        $here = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $there = Borrower::factory()->create(['branch_id' => $this->far->id]);
        $base = ['transactor_user_id' => $this->admin->id, 'transaction_date' => now()];

        $this->rows = [
            'member_here' => GCashTransaction::factory()->create([...$base, 'borrower_id' => $here->id, 'type' => 'cash_in', 'status' => 'paid', 'charge_amount' => 10]),
            'member_there' => GCashTransaction::factory()->create([...$base, 'borrower_id' => $there->id, 'type' => 'cash_in', 'status' => 'paid', 'charge_amount' => 20]),
            'walk_in_here' => GCashTransaction::factory()->forNonMember($this->walkInPerson)->create([...$base, 'branch_id' => $this->branch->id, 'type' => 'cash_out', 'status' => 'completed', 'charge_amount' => 15.5]),
            'walk_in_there' => GCashTransaction::factory()->forNonMember($this->walkInPerson)->create([...$base, 'branch_id' => $this->far->id, 'type' => 'cash_out', 'status' => 'completed', 'charge_amount' => 40]),
            'walk_in_head_office' => GCashTransaction::factory()->forNonMember($this->walkInPerson)->create([...$base, 'branch_id' => null, 'type' => 'cash_in', 'status' => 'paid', 'charge_amount' => 80]),
            'pending_walk_in_here' => GCashTransaction::factory()->forNonMember($this->walkInPerson)->create([...$base, 'branch_id' => $this->branch->id, 'type' => 'cash_in', 'status' => 'pending']),
            'pending_walk_in_there' => GCashTransaction::factory()->forNonMember($this->walkInPerson)->create([...$base, 'branch_id' => $this->far->id, 'type' => 'cash_in', 'status' => 'pending']),
            'pending_walk_in_head_office' => GCashTransaction::factory()->forNonMember($this->walkInPerson)->create([...$base, 'branch_id' => null, 'type' => 'cash_in', 'status' => 'pending']),
        ];
    }

    public function test_the_list_shows_a_branch_user_only_their_branchs_walk_ins(): void
    {
        $this->actingAs($this->branchUser());

        $this->assertEqualsCanonicalizing(
            $this->ids(['member_here', 'walk_in_here', 'pending_walk_in_here']),
            array_column($this->getJson('/api/gcash/transactions?per_page=100')->assertOk()->json('data'), 'id'),
        );
    }

    public function test_view_all_still_lists_every_row(): void
    {
        $this->assertCount(8, $this->getJson('/api/gcash/transactions?per_page=100')->assertOk()->json('data'));
    }

    public function test_another_branchs_or_head_offices_walk_in_answers_404_like_a_missing_one(): void
    {
        $this->actingAs($this->branchUser());

        foreach (['pending_walk_in_there', 'pending_walk_in_head_office'] as $key) {
            $this->patchJson("/api/gcash/transactions/{$this->rows[$key]->id}/paid")->assertNotFound();
            $this->assertSame('pending', $this->rows[$key]->fresh()->status);
        }
        $this->patchJson('/api/gcash/transactions/999999/paid')->assertNotFound();

        $this->patchJson("/api/gcash/transactions/{$this->rows['pending_walk_in_here']->id}/paid")->assertOk();
    }

    public function test_the_income_report_lists_counts_and_totals_only_the_callers_rows(): void
    {
        $this->actingAs($this->branchUser());

        $data = $this->getJson($this->incomeUrl())->assertOk()->json('data');

        $this->assertEqualsCanonicalizing($this->ids(['member_here', 'walk_in_here']), array_column($data['transactions'], 'id'));
        $this->assertEquals(25.5, $data['total_income']);
        $this->assertSame(1, $data['cash_in_count']);
        $this->assertSame(1, $data['cash_out_count']);
    }

    public function test_the_income_report_for_view_all_counts_every_row_by_type(): void
    {
        $data = $this->getJson($this->incomeUrl())->assertOk()->json('data');

        $this->assertCount(5, $data['transactions']);
        $this->assertEquals(165.5, $data['total_income']);
        $this->assertSame(3, $data['cash_in_count'], 'Pending Cash Ins are deferred and left out, as in the total.');
        $this->assertSame(2, $data['cash_out_count']);
    }

    public function test_the_pending_report_shows_only_the_callers_rows(): void
    {
        $this->actingAs($this->branchUser());

        $this->assertSame(
            $this->ids(['pending_walk_in_here']),
            array_column($this->getJson('/api/gcash/reports/pending')->assertOk()->json('data'), 'id'),
        );
    }

    public function test_a_walk_ins_transaction_count_covers_only_rows_the_caller_can_see(): void
    {
        $this->actingAs($this->branchUser());

        $row = collect($this->getJson('/api/gcash/non-members?per_page=100')->assertOk()->json('data'))
            ->firstWhere('id', $this->walkInPerson->id);

        $this->assertSame(2, $row['transaction_count']);

        $this->actingAs($this->admin);
        $row = collect($this->getJson('/api/gcash/non-members?per_page=100')->assertOk()->json('data'))
            ->firstWhere('id', $this->walkInPerson->id);
        $this->assertSame(6, $row['transaction_count']);
    }

    public function test_a_user_with_no_branch_sees_no_rows(): void
    {
        $user = User::factory()->create(['branch_id' => null]);
        $user->assignRole($this->gcashRole());
        $this->actingAs($user);

        $this->assertSame([], app(BranchAccess::class)->for($user));
        $this->assertSame([], $this->getJson('/api/gcash/transactions?per_page=100')->assertOk()->json('data'));
        $this->assertSame(0, $this->getJson($this->incomeUrl())->assertOk()->json('data.cash_in_count'));
    }

    private function branchUser(): User
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($this->gcashRole());

        return $user;
    }

    private function gcashRole(): Role
    {
        $role = Role::firstOrCreate(['name' => 'gcash_teller_fxa', 'guard_name' => 'web']);
        $role->syncPermissions(['gcash:view', 'gcash:transact']);

        return $role;
    }

    /**
     * @param  list<string>  $keys
     * @return list<int>
     */
    private function ids(array $keys): array
    {
        return array_map(fn (string $key): int => $this->rows[$key]->id, $keys);
    }

    private function incomeUrl(): string
    {
        return '/api/gcash/reports/income?start_date='.now()->subDay()->toDateString().'&end_date='.now()->addDay()->toDateString();
    }
}
