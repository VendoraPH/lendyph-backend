<?php

namespace Tests\Feature\R7Integrity;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `GET /users` rows carry `can_manage` (round r7, decision 3): false exactly
 * when edit, reset password, deactivate and reactivate would answer the masked
 * 404 for the caller. One rule decides both (App\Services\UserReach), so the
 * row and the four requests can't drift apart. It's worked out from the rows'
 * loaded relations, so the query count doesn't grow with the page.
 *
 * Only the list carries it: /auth/me, login, GET /users/{id} and nested actors
 * don't.
 */
class UserCanManageTest extends TestCase
{
    use SetupLendyPH;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
    }

    public function test_a_branch_caller_s_rows_match_the_four_requests(): void
    {
        $peer = $this->userIn([$this->branch], 'viewer');
        $inactivePeer = $this->userIn([$this->branch], 'viewer', ['status' => 'inactive']);
        $officer = $this->userIn([$this->branch], 'loan_officer');
        $overseer = $this->userIn([$this->branch], 'manager');
        $bridging = $this->userIn([$this->branch, $this->far], 'viewer');
        $board = $this->userIn([$this->branch], 'viewer');
        $board->givePermissionTo('branches:view_all');

        $caller = $this->branchManager();
        $this->actingAs($caller);

        $rows = $this->canManageById();

        $this->assertTrue($rows[$peer->id], 'A peer inside the caller\'s branches and permissions.');
        $this->assertTrue($rows[$inactivePeer->id], 'An inactive peer (reactivate).');
        $this->assertFalse($rows[$officer->id], 'Holds permissions the caller lacks.');
        $this->assertFalse($rows[$overseer->id], 'Holds permissions the caller lacks.');
        $this->assertFalse($rows[$bridging->id], 'Works in a branch the caller doesn\'t.');
        $this->assertFalse($rows[$board->id], 'Holds branches:view_all.');
        $this->assertTrue($rows[$caller->id], 'The caller always reaches themselves.');

        $this->assertParity($rows, $caller);
    }

    public function test_a_caller_s_deactivated_role_grants_them_no_reach(): void
    {
        $approvals = Role::create(['name' => 'r7_approvals', 'guard_name' => 'web', 'is_system' => false, 'is_active' => true]);
        $approvals->syncPermissions(['loans:approve']);
        $switchedOff = Role::create(['name' => 'r7_switched_off', 'guard_name' => 'web', 'is_system' => false, 'is_active' => false]);
        $switchedOff->syncPermissions(['loans:approve']);

        $approver = $this->userIn([$this->branch], 'viewer');
        $approver->assignRole($approvals);
        $holdsOnlyTheOffRole = $this->userIn([$this->branch], 'viewer');
        $holdsOnlyTheOffRole->assignRole($switchedOff);

        $caller = $this->branchManager();
        $caller->assignRole($switchedOff);
        $this->actingAs($caller);

        $rows = $this->canManageById();

        $this->assertFalse($rows[$approver->id], 'loans:approve comes only from the caller\'s deactivated role.');
        $this->assertTrue($rows[$holdsOnlyTheOffRole->id], 'A deactivated role grants the target nothing either.');

        $this->assertParity($rows, $caller);
    }

    public function test_a_user_with_only_the_legacy_branch_column_is_read_by_that_column(): void
    {
        $legacyPeer = $this->legacyUserIn($this->branch, 'viewer');
        $bridging = $this->userIn([$this->branch, $this->far], 'viewer');
        $peer = $this->userIn([$this->branch], 'viewer');

        $caller = $this->branchManager();
        $this->actingAs($caller);
        $rows = $this->canManageById();

        $this->assertTrue($rows[$legacyPeer->id], 'Only users.branch_id, inside the caller\'s branch.');
        $this->assertParity($rows, $caller);

        $legacyCaller = $this->legacyUserIn($this->branch, 'viewer');
        $legacyCaller->givePermissionTo(['users:view', 'users:update', 'users:reset_password', 'users:delete']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($legacyCaller);
        $rows = $this->canManageById();

        $this->assertTrue($rows[$peer->id], 'A caller with only users.branch_id reaches that branch.');
        $this->assertFalse($rows[$bridging->id]);
        $this->assertParity($rows, $legacyCaller);
    }

    public function test_a_view_all_caller_manages_everyone_but_the_super_admin(): void
    {
        $officer = $this->userIn([$this->branch], 'loan_officer');
        $elsewhere = $this->userIn([$this->far], 'manager');

        $caller = $this->userIn([$this->branch], 'admin');
        $this->actingAs($caller);

        $rows = $this->canManageById();

        $this->assertTrue($rows[$officer->id]);
        $this->assertTrue($rows[$elsewhere->id], 'branches:view_all reaches every branch.');
        $this->assertFalse($rows[$this->admin->id], 'Only a super_admin manages a super_admin.');
        $this->assertTrue($rows[$caller->id]);

        $this->assertParity($rows, $caller);
    }

    public function test_a_super_admin_manages_every_row(): void
    {
        $other = $this->userIn([$this->far], 'super_admin');
        $this->userIn([$this->branch], 'loan_officer');

        $rows = $this->canManageById();

        $this->assertTrue($rows[$other->id], 'A super_admin manages another super_admin.');
        $this->assertNotContains(false, $rows);
    }

    public function test_only_the_list_carries_can_manage(): void
    {
        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonStructure(['data' => [['can_manage']]])
            ->assertJsonMissingPath('data.0.permissions');

        $this->getJson('/api/auth/me')->assertOk()->assertJsonMissingPath('data.can_manage');
        $this->getJson("/api/users/{$this->admin->id}")->assertOk()->assertJsonMissingPath('data.can_manage');

        $this->postJson('/api/auth/login', ['login' => 'super_admin', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.username', 'super_admin')
            ->assertJsonMissingPath('user.can_manage');

        $this->actingAs($this->admin);
        $loan = $this->createReleasedLoan();
        $this->getJson("/api/loans/{$loan->id}")
            ->assertOk()
            ->assertJsonPath('data.created_by_user.id', $this->admin->id)
            ->assertJsonMissingPath('data.created_by_user.can_manage')
            ->assertJsonMissingPath('data.released_by_user.can_manage');
    }

    public function test_the_query_count_stays_the_same_as_rows_grow(): void
    {
        $this->assertQueryCountIsConstant($this->branchManager());
    }

    public function test_the_query_count_stays_the_same_for_a_view_all_caller(): void
    {
        $this->assertQueryCountIsConstant($this->userIn([$this->branch], 'admin'));
    }

    private function assertQueryCountIsConstant(User $caller): void
    {
        $this->userIn([$this->branch], 'viewer');
        $this->userIn([$this->branch], 'loan_officer');

        // Spatie loads its permission cache on the first check of a run (two
        // queries, once); warm it so only per-row queries could differ.
        $this->countQueries($caller);
        $small = $this->countQueries($caller);

        foreach (range(1, 8) as $i) {
            $user = $this->userIn($i % 2 === 0 ? [$this->branch] : [$this->branch, $this->far], $i % 3 === 0 ? 'manager' : 'viewer');
            if ($i % 4 === 0) {
                $user->givePermissionTo('users:view');
            }
        }

        $large = $this->countQueries($caller);

        $this->assertGreaterThan($small['rows'], $large['rows']);
        $this->assertSame($small['queries'], $large['queries'], 'can_manage adds no query per row.');
    }

    /**
     * @return array{rows: int, queries: int}
     */
    private function countQueries(User $caller): array
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::findOrFail($caller->id));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $this->getJson('/api/users?per_page=100')->assertOk()->json('data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return ['rows' => count($rows), 'queries' => $queries];
    }

    /**
     * @return array<int, bool>
     */
    private function canManageById(): array
    {
        return collect($this->getJson('/api/users?per_page=100')->assertOk()->json('data'))
            ->mapWithKeys(fn (array $row) => [$row['id'] => $row['can_manage']])
            ->all();
    }

    /**
     * Each row's `can_manage` against the four requests' answers: false
     * exactly when every one of them answers 404. The caller's own row runs
     * last, since resetting their own password changes what they may do next.
     *
     * @param  array<int, bool>  $rows
     */
    private function assertParity(array $rows, User $caller): void
    {
        $ids = array_keys($rows);
        usort($ids, fn (int $a, int $b) => ($a === $caller->id) <=> ($b === $caller->id));

        foreach ($ids as $id) {
            $answers = [
                'update' => $this->putJson("/api/users/{$id}", ['first_name' => 'Parity']),
                'deactivate' => $this->patchJson("/api/users/{$id}/deactivate"),
                'reactivate' => $this->patchJson("/api/users/{$id}/reactivate"),
                'reset-password' => $this->postJson("/api/users/{$id}/reset-password", [
                    'password' => 'newpassword1', 'password_confirmation' => 'newpassword1',
                ]),
            ];

            foreach ($answers as $request => $response) {
                /** @var TestResponse $response */
                $this->assertSame(
                    ! $rows[$id],
                    $response->status() === 404,
                    "User {$id}: can_manage is ".var_export($rows[$id], true)." but {$request} answered {$response->status()}.",
                );
            }
        }
    }

    /**
     * @param  list<Branch>  $branches
     * @param  array<string, mixed>  $attributes
     */
    private function userIn(array $branches, string $role, array $attributes = []): User
    {
        $user = User::factory()->inBranches(...$branches)->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    /**
     * A user with no `branch_user` rows, only the legacy `users.branch_id`.
     */
    private function legacyUserIn(Branch $branch, string $role): User
    {
        $user = $this->userIn([$branch], $role);
        $user->branches()->detach();

        return $user->refresh();
    }

    /**
     * A user manager without `branches:view_all`: every `users:*` permission,
     * in Main Branch only.
     */
    private function branchManager(): User
    {
        $user = $this->userIn([$this->branch], 'viewer');
        $user->givePermissionTo(['users:view', 'users:create', 'users:update', 'users:reset_password', 'users:delete']);

        return $user;
    }
}
