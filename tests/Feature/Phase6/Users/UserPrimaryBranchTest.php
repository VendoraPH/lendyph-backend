<?php

namespace Tests\Feature\Phase6\Users;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE6 (multi-branch audit D9, decision 6): admins choose each
 * user's primary branch (`primary_branch_id`). It must be one of the user's
 * assigned branches; none reads "Organisation-wide" and is only for a user who
 * sees every branch. Each change is audited in words.
 */
class UserPrimaryBranchTest extends TestCase
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

    public function test_create_takes_the_chosen_primary_branch(): void
    {
        $this->postJson('/api/users', $this->newUser([
            'branch_ids' => [$this->second->id, $this->third->id],
            'primary_branch_id' => $this->third->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.primary_branch_id', $this->third->id)
            ->assertJsonPath('data.primary_branch_name', 'Third');

        $user = User::where('username', 'primary')->firstOrFail();
        $this->assertSame($this->third->id, $user->branch_id);

        $row = AuditLog::query()->where('auditable_type', $user->getMorphClass())
            ->where('auditable_id', $user->id)->where('action', 'created')->firstOrFail();
        $this->assertSame('User primary created with primary branch Third.', $row->description);
    }

    public function test_create_refuses_a_primary_branch_the_user_is_not_assigned_to(): void
    {
        $this->postJson('/api/users', $this->newUser([
            'branch_ids' => [$this->second->id],
            'primary_branch_id' => $this->third->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['primary_branch_id' => 'The primary branch must be one of the branches this user is assigned to.']);

        $this->assertFalse(User::where('username', 'primary')->exists());
    }

    public function test_organisation_wide_is_refused_for_a_role_that_does_not_see_every_branch(): void
    {
        $this->postJson('/api/users', $this->newUser([
            'branch_ids' => [$this->second->id],
            'primary_branch_id' => null,
            'role' => 'loan_officer',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['primary_branch_id' => 'Only a user whose role sees all branches can be Organisation-wide. Choose one of their branches as the primary branch.']);
    }

    public function test_organisation_wide_is_allowed_for_a_role_that_sees_every_branch(): void
    {
        $this->postJson('/api/users', $this->newUser([
            'branch_ids' => [$this->second->id],
            'primary_branch_id' => null,
            'role' => 'manager',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.primary_branch_id', null)
            ->assertJsonPath('data.primary_branch_name', 'Organisation-wide');

        $user = User::where('username', 'primary')->firstOrFail();
        $this->assertNull($user->branch_id);
        $this->assertSame([$this->second->id], $user->branches()->pluck('branches.id')->all());
    }

    public function test_a_create_without_the_key_keeps_the_first_assigned_branch(): void
    {
        $this->postJson('/api/users', $this->newUser([
            'branch_ids' => [$this->third->id, $this->second->id],
        ]))->assertCreated();

        $this->assertSame($this->third->id, User::where('username', 'primary')->value('branch_id'));
    }

    public function test_update_changes_the_primary_branch_and_audits_it_in_words(): void
    {
        $user = $this->officer([$this->second, $this->third]);

        $this->putJson("/api/users/{$user->id}", ['primary_branch_id' => $this->third->id])
            ->assertOk()
            ->assertJsonPath('data.primary_branch_id', $this->third->id);

        $this->assertSame($this->third->id, $user->fresh()->branch_id);
        $this->assertSame(
            [$this->second->id, $this->third->id],
            $user->branches()->orderBy('branches.id')->pluck('branches.id')->all(),
            'Choosing the primary branch leaves the assignment as it is.',
        );

        $row = AuditLog::query()->where('auditable_type', $user->getMorphClass())
            ->where('auditable_id', $user->id)->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame("Primary branch for {$user->username} changed from Second to Third.", $row->description);
    }

    public function test_update_refuses_a_primary_branch_the_user_is_not_assigned_to(): void
    {
        $user = $this->officer([$this->second]);

        $this->putJson("/api/users/{$user->id}", ['primary_branch_id' => $this->third->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('primary_branch_id');

        $this->assertSame($this->second->id, $user->fresh()->branch_id);
    }

    public function test_update_checks_the_primary_branch_against_the_new_assignment(): void
    {
        $user = $this->officer([$this->second]);

        $this->putJson("/api/users/{$user->id}", [
            'branch_ids' => [$this->second->id, $this->third->id],
            'primary_branch_id' => $this->third->id,
        ])->assertOk();

        $this->assertSame($this->third->id, $user->fresh()->branch_id);

        $this->putJson("/api/users/{$user->id}", [
            'branch_ids' => [$this->second->id],
            'primary_branch_id' => $this->third->id,
        ])->assertStatus(422)->assertJsonValidationErrors('primary_branch_id');
    }

    public function test_organisation_wide_on_update_needs_a_user_who_sees_every_branch(): void
    {
        $officer = $this->officer([$this->second]);
        $this->putJson("/api/users/{$officer->id}", ['primary_branch_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('primary_branch_id');
        $this->assertSame($this->second->id, $officer->fresh()->branch_id);

        $manager = $this->officer([$this->second], 'manager');
        $this->putJson("/api/users/{$manager->id}", ['primary_branch_id' => null])->assertOk();
        $this->assertNull($manager->fresh()->branch_id);

        $row = AuditLog::query()->where('auditable_type', $manager->getMorphClass())
            ->where('auditable_id', $manager->id)->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame("Primary branch for {$manager->username} changed from Second to Organisation-wide.", $row->description);
    }

    public function test_an_organisation_wide_user_stays_so_when_an_old_screen_changes_their_branches(): void
    {
        $manager = $this->officer([$this->second], 'manager');
        $manager->forceFill(['branch_id' => null])->save();

        $this->putJson("/api/users/{$manager->id}", [
            'branch_ids' => [$this->second->id, $this->third->id],
            'branch_id' => $this->second->id,
        ])->assertOk();

        $this->assertNull($manager->fresh()->branch_id);
    }

    public function test_a_role_change_that_loses_every_branch_needs_a_primary_branch(): void
    {
        $manager = $this->officer([$this->second], 'manager');
        $manager->forceFill(['branch_id' => null])->save();

        $this->putJson("/api/users/{$manager->id}", ['role' => 'loan_officer'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('primary_branch_id');

        $this->putJson("/api/users/{$manager->id}", ['role' => 'loan_officer', 'primary_branch_id' => $this->second->id])
            ->assertOk();

        $this->assertSame($this->second->id, $manager->fresh()->branch_id);
    }

    public function test_repeating_the_stored_primary_branch_is_nothing_to_update(): void
    {
        $user = $this->officer([$this->second, $this->third]);

        $this->putJson("/api/users/{$user->id}", ['primary_branch_id' => $this->second->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('changes');
    }

    public function test_existing_users_keep_their_primary_branch_when_the_key_is_absent(): void
    {
        $user = $this->officer([$this->second, $this->third]);
        $user->forceFill(['branch_id' => $this->third->id])->save();

        $this->putJson("/api/users/{$user->id}", ['first_name' => 'Renamed'])->assertOk();

        $this->assertSame($this->third->id, $user->fresh()->branch_id);
    }

    public function test_a_branch_limited_admin_sets_a_primary_branch_within_their_branches(): void
    {
        $admin = $this->branchAdmin([$this->second, $this->third]);
        $user = User::factory()->inBranches($this->second, $this->third)->create();

        $this->actingAs($admin)
            ->putJson("/api/users/{$user->id}", ['primary_branch_id' => $this->third->id])
            ->assertOk();
        $this->assertSame($this->third->id, $user->fresh()->branch_id);

        $this->actingAs($admin)
            ->putJson("/api/users/{$user->id}", ['primary_branch_id' => $this->branch->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('primary_branch_id');

        $this->actingAs($admin)
            ->putJson("/api/users/{$user->id}", ['primary_branch_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('primary_branch_id');
    }

    /**
     * Review finding: like their branches, a user who doesn't see every
     * branch can't change their own primary branch.
     */
    public function test_a_branch_limited_user_cannot_change_their_own_primary_branch(): void
    {
        $admin = $this->branchAdmin([$this->second, $this->third]);

        $this->actingAs($admin)
            ->putJson("/api/users/{$admin->id}", ['primary_branch_id' => $this->third->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['primary_branch_id' => 'You cannot change your own primary branch. Another administrator has to do it for you.']);

        $this->assertSame($this->second->id, $admin->fresh()->branch_id);
    }

    /**
     * @param  list<Branch>  $branches
     */
    private function branchAdmin(array $branches): User
    {
        $role = Role::create(['name' => 'mb6_branch_admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['users:view', 'users:create', 'users:update', 'loans:view']);
        $user = User::factory()->inBranches(...$branches)->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  list<Branch>  $branches
     */
    private function officer(array $branches, string $role = 'loan_officer'): User
    {
        $user = User::factory()->inBranches(...$branches)->create();
        $user->assignRole(Role::where('name', $role)->firstOrFail());

        return $user;
    }

    private function newUser(array $overrides = []): array
    {
        return [
            'first_name' => 'Primary',
            'last_name' => 'Branch',
            'username' => 'primary',
            'email' => 'primary@lendyph.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'loan_officer',
            ...$overrides,
        ];
    }
}
