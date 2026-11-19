<?php

namespace Tests\Feature\Phase6\Users;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE6: a user's branch history, read from audit rows, newest
 * first and in words (GET /users/{user}/branch-history). It needs
 * `users:view` and the caller's reach (UserReach); anything else answers the
 * same 404 as a missing user.
 */
class UserBranchHistoryTest extends TestCase
{
    use SetupLendyPH;

    private Branch $second;

    private Branch $third;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->second = Branch::factory()->create(['name' => 'Second', 'code' => 'SEC']);
        $this->third = Branch::factory()->create(['name' => 'Third', 'code' => 'THR']);
    }

    public function test_it_lists_assignments_and_primary_changes_newest_first_in_words(): void
    {
        $this->postJson('/api/users', [
            'first_name' => 'History',
            'last_name' => 'User',
            'username' => 'history',
            'email' => 'history@lendyph.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'branch_ids' => [$this->second->id],
            'role' => 'loan_officer',
        ])->assertCreated();

        $user = User::where('username', 'history')->firstOrFail();

        $this->putJson("/api/users/{$user->id}", ['branch_ids' => [$this->second->id, $this->third->id]])->assertOk();
        $this->putJson("/api/users/{$user->id}", ['primary_branch_id' => $this->third->id])->assertOk();
        $this->putJson("/api/users/{$user->id}", ['first_name' => 'Renamed'])->assertOk();

        $response = $this->getJson("/api/users/{$user->id}/branch-history")->assertOk();

        $this->assertSame([
            'Primary branch changed from Second to Third.',
            'Branches changed from Second to Second, Third.',
            'Assigned to Second.',
            'Primary branch set to Second.',
        ], array_column($response->json('data'), 'description'));

        $this->assertSame(['primary', 'branches', 'branches', 'primary'], array_column($response->json('data'), 'kind'));
        $this->assertSame('Third', $response->json('data.0.to'));
        $this->assertSame('Second', $response->json('data.0.from'));
        $this->assertSame(['Second', 'Third'], $response->json('data.1.to'));
        $this->assertSame($this->admin->full_name, $response->json('data.0.by'));
        $this->assertNotNull($response->json('data.0.at'));
    }

    public function test_organisation_wide_reads_in_words(): void
    {
        $manager = User::factory()->inBranches($this->second)->create();
        $manager->assignRole(Role::where('name', 'manager')->firstOrFail());

        $this->putJson("/api/users/{$manager->id}", ['primary_branch_id' => null])->assertOk();

        $this->getJson("/api/users/{$manager->id}/branch-history")
            ->assertOk()
            ->assertJsonPath('data.0.description', 'Primary branch changed from Second to Organisation-wide.')
            ->assertJsonPath('data.0.to', 'Organisation-wide');
    }

    public function test_a_system_correction_reads_as_the_system(): void
    {
        $user = User::factory()->create(['branch_id' => null]);

        AuditLog::create([
            'user_id' => null,
            'action' => 'branches_changed',
            'auditable_type' => $user->getMorphClass(),
            'auditable_id' => $user->id,
            'old_values' => ['branch_ids' => []],
            'new_values' => ['branch_ids' => [$this->second->id]],
            'description' => 'System correction: Branch assignment changed.',
        ]);

        $this->getJson("/api/users/{$user->id}/branch-history")
            ->assertOk()
            ->assertJsonPath('data.0.description', 'Branches changed from none to Second.')
            ->assertJsonPath('data.0.by', 'System');
    }

    public function test_it_needs_users_view(): void
    {
        $target = User::factory()->inBranches($this->branch)->create();
        $caller = $this->scopedCaller(['loans:view'], $this->branch);

        $this->actingAs($caller)
            ->getJson("/api/users/{$target->id}/branch-history")
            ->assertNotFound();
    }

    public function test_a_scoped_caller_reads_a_user_they_reach(): void
    {
        $target = User::factory()->inBranches($this->second)->create();
        $caller = $this->scopedCaller(['users:view'], $this->second);

        $this->actingAs($caller)
            ->getJson("/api/users/{$target->id}/branch-history")
            ->assertOk();
    }

    public function test_a_scoped_caller_cannot_read_another_branchs_user(): void
    {
        $target = User::factory()->inBranches($this->third)->create();
        $caller = $this->scopedCaller(['users:view'], $this->second);

        $this->actingAs($caller)
            ->getJson("/api/users/{$target->id}/branch-history")
            ->assertNotFound();
    }

    public function test_a_scoped_caller_cannot_read_a_user_beyond_their_reach(): void
    {
        $target = User::factory()->inBranches($this->second)->create();
        $target->assignRole(Role::where('name', 'manager')->firstOrFail());
        $caller = $this->scopedCaller(['users:view'], $this->second);

        $this->actingAs($caller)
            ->getJson("/api/users/{$target->id}/branch-history")
            ->assertNotFound();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function scopedCaller(array $permissions, Branch $branch): User
    {
        $role = Role::create(['name' => 'mb6_history_'.md5(implode(',', $permissions).$branch->id), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->inBranches($branch)->create();
        $user->assignRole($role);

        return $user;
    }
}
