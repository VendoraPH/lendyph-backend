<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\GCashTransaction;
use App\Models\User;
use App\Services\BranchAccess;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 3 follow-ups, item B4. Pending Cash In report rows carry the branch
 * whose books the row posts in: a walk-in's own recorded branch, a member's
 * branch for a member, and none ("Head office") for a walk-in recorded with no
 * branch. The report's branch scope is unchanged: a branch user sees their own
 * members' rows and every walk-in row.
 */
class GCashPendingBranchTest extends TestCase
{
    use SetupLendyPH;

    private Branch $far;

    private GCashTransaction $member;

    private GCashTransaction $walkIn;

    private GCashTransaction $headOffice;

    private GCashTransaction $farMember;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);

        $pending = ['type' => 'cash_in', 'status' => 'pending', 'transactor_user_id' => $this->admin->id];
        $this->member = GCashTransaction::factory()->create([...$pending, 'transaction_date' => now()->subDays(4),
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id]);
        $this->walkIn = GCashTransaction::factory()->forNonMember()->create([...$pending, 'transaction_date' => now()->subDays(3), 'branch_id' => $this->far->id]);
        $this->headOffice = GCashTransaction::factory()->forNonMember()->create([...$pending, 'transaction_date' => now()->subDays(2), 'branch_id' => null]);
        $this->farMember = GCashTransaction::factory()->create([...$pending, 'transaction_date' => now()->subDay(),
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->far->id])->id]);
    }

    /** @return array<int, array{0: int|null, 1: string}> rows by transaction id: [branch_id, branch_name] */
    private function branchesOnTheReport(): array
    {
        return collect($this->getJson('/api/gcash/reports/pending')->assertOk()->json('data'))
            ->mapWithKeys(fn (array $row): array => [$row['id'] => [$row['branch_id'], $row['branch_name']]])
            ->all();
    }

    public function test_each_row_carries_the_branch_it_posts_in(): void
    {
        $this->actingAs($this->admin);

        $this->assertSame([
            $this->member->id => [$this->branch->id, $this->branch->name],
            $this->walkIn->id => [$this->far->id, 'Far Branch'],
            $this->headOffice->id => [null, 'Head office'],
            $this->farMember->id => [$this->far->id, 'Far Branch'],
        ], $this->branchesOnTheReport());
    }

    public function test_the_branch_scope_is_unchanged(): void
    {
        $role = Role::create(['name' => 'gcash_viewer_fu', 'guard_name' => 'web']);
        $role->syncPermissions(['gcash:view']);
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);
        $this->actingAs($user);

        $this->assertSame([
            $this->member->id => [$this->branch->id, $this->branch->name],
            $this->walkIn->id => [$this->far->id, 'Far Branch'],
            $this->headOffice->id => [null, 'Head office'],
        ], $this->branchesOnTheReport(), 'Another branch\'s member row stays hidden; walk-in rows stay visible.');

        $user->givePermissionTo(BranchAccess::VIEW_ALL);
        $this->assertCount(4, $this->branchesOnTheReport());
    }
}
