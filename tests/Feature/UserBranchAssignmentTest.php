<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A user may be assigned to several branches, and for one release the API has
 * to speak about that in two shapes at once.
 *
 * `branch` / `branch_id` (singular, unchanged) go on working because every
 * already-signed-in frontend session rehydrates the old shape out of
 * localStorage with no migration step, and because UserResource is the nested
 * actor payload for audit logs, borrowers and loans. `branches` / `branch_ids`
 * (plural) are the assignment itself. The two must never contradict each other:
 * `users.branch_id` is maintained as a MEMBER of the assigned set, so the
 * singular shape can never name a branch the user is not in.
 */
class UserBranchAssignmentTest extends TestCase
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

    // -----------------------------------------------------------------
    // The backfill — the part that runs against five live databases
    // -----------------------------------------------------------------

    /**
     * Every user who has a `branch_id` comes out of the migration with exactly
     * one pivot row, naming the same branch.
     *
     * Deleting the pivot first puts the database back in the state it is in the
     * instant before this migration runs on a live box: a populated column and
     * no pivot at all. That is the only state the backfill has to be correct
     * for, and it cannot be reached by letting `migrate:fresh` run the
     * migration — there are no users yet when it does.
     */
    public function test_the_backfill_gives_every_user_with_a_branch_exactly_one_pivot_row(): void
    {
        $here = User::factory()->create(['branch_id' => $this->branch->id]);
        $there = User::factory()->create(['branch_id' => $this->second->id]);
        $branchless = User::factory()->create(['branch_id' => null]);

        DB::table('branch_user')->delete();

        $inserted = $this->migration()->backfill();

        $this->assertSame(
            User::whereNotNull('branch_id')->count(),
            $inserted,
            'The backfill must cover every user carrying a branch_id, not just the ones it happened to see first.',
        );

        $this->assertSame([$this->branch->id], $this->branchIdsOf($here));
        $this->assertSame([$this->second->id], $this->branchIdsOf($there));
        $this->assertSame([$this->branch->id], $this->branchIdsOf($this->admin));

        $this->assertSame(
            [],
            $this->branchIdsOf($branchless),
            'A null branch_id is not an assignment to branch 0 — it must produce no pivot row at all.',
        );
    }

    /**
     * Re-running the backfill is a no-op, not a duplicate-key failure.
     *
     * Five deployments run this, and an operator re-running a migration by hand
     * — or a deploy that replays one — must not be an outage.
     */
    public function test_the_backfill_is_safe_to_run_twice(): void
    {
        $user = User::factory()->create(['branch_id' => $this->second->id]);

        DB::table('branch_user')->delete();

        $first = $this->migration()->backfill();
        $second = $this->migration()->backfill();

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, 'The second pass must find nothing left to do.');
        $this->assertSame([$this->second->id], $this->branchIdsOf($user));
        $this->assertSame(
            1,
            DB::table('branch_user')->where('user_id', $user->id)->count(),
            'A second run must not double the assignment.',
        );
    }

    // -----------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------

    public function test_creating_a_user_with_branch_ids_assigns_all_of_them(): void
    {
        $response = $this->postJson('/api/users', $this->newUser([
            'branch_ids' => [$this->second->id, $this->third->id],
        ]));

        $response->assertCreated();

        $user = User::where('username', 'multi')->firstOrFail();

        $this->assertSame([$this->second->id, $this->third->id], $this->branchIdsOf($user));
        $this->assertSame(
            $this->second->id,
            $user->branch_id,
            'The legacy column has to keep naming one of the assigned branches.',
        );
    }

    /**
     * The shape every client sends today, untouched.
     */
    public function test_creating_a_user_with_only_branch_id_still_works(): void
    {
        $this->postJson('/api/users', $this->newUser([
            'branch_id' => $this->second->id,
        ]))->assertCreated();

        $user = User::where('username', 'multi')->firstOrFail();

        $this->assertSame($this->second->id, $user->branch_id);
        $this->assertSame(
            [$this->second->id],
            $this->branchIdsOf($user),
            'A singular branch_id still has to produce the pivot row, or the user is branchless in the new shape.',
        );
    }

    public function test_a_body_carrying_neither_shape_is_refused(): void
    {
        $payload = $this->newUser();
        unset($payload['branch_id']);

        $this->postJson('/api/users', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id', 'branch_ids']);
    }

    public function test_branch_ids_must_name_real_branches(): void
    {
        $this->postJson('/api/users', $this->newUser([
            'branch_ids' => [$this->second->id, 999999],
        ]))->assertStatus(422)->assertJsonValidationErrors(['branch_ids.1']);
    }

    // -----------------------------------------------------------------
    // The response shape — both, always
    // -----------------------------------------------------------------

    public function test_every_user_response_carries_both_shapes(): void
    {
        $created = $this->postJson('/api/users', $this->newUser([
            'branch_ids' => [$this->second->id, $this->third->id],
        ]))->assertCreated();

        $userId = $created->json('data.id');

        foreach ([$created, $this->getJson("/api/users/{$userId}")->assertOk()] as $response) {
            $this->assertSame($this->second->id, $response->json('data.branch.id'));
            $this->assertSame(
                [$this->second->id, $this->third->id],
                $this->sortedIds($response->json('data.branches')),
            );
        }

        $row = collect($this->getJson('/api/users')->assertOk()->json('data'))
            ->firstWhere('id', $userId);

        $this->assertSame($this->second->id, $row['branch']['id'], 'The list has to carry the legacy shape too.');
        $this->assertSame([$this->second->id, $this->third->id], $this->sortedIds($row['branches']));
    }

    /**
     * `/auth/me` and the login payload are what the persisted auth store is
     * built from, so both shapes have to survive the round trip a rehydrating
     * client makes.
     */
    public function test_the_authenticated_user_payload_carries_both_shapes(): void
    {
        $user = User::factory()->inBranches($this->second, $this->third)->create();
        $user->assignRole('loan_officer');

        $me = $this->actingAs($user)->getJson('/api/auth/me')->assertOk();

        $this->assertSame($this->second->id, $me->json('data.branch.id'));
        $this->assertSame(
            [$this->second->id, $this->third->id],
            $this->sortedIds($me->json('data.branches')),
        );
    }

    // -----------------------------------------------------------------
    // Updating — where the change-detection trap lives
    // -----------------------------------------------------------------

    /**
     * THE regression this feature is most likely to ship with.
     *
     * `branch_ids` is a pivot, so `fill()` drops it and `isDirty()` is false.
     * `UpdateUserRequest::changesAnyColumn()` derives its column list
     * mechanically from `array_keys(rules())`, so adding the rule is not enough
     * to make the request notice — a payload whose only change is branches
     * would be refused 422 "Nothing to update" while writing nothing.
     */
    public function test_a_branch_only_edit_saves_rather_than_422ing(): void
    {
        $user = User::factory()->create(['branch_id' => $this->second->id]);
        $user->assignRole('viewer');

        $this->putJson("/api/users/{$user->id}", [
            'branch_ids' => [$this->second->id, $this->third->id],
        ])->assertOk();

        $this->assertSame([$this->second->id, $this->third->id], $this->branchIdsOf($user));
    }

    /**
     * The same set in a different order assigns exactly the same branches, so
     * it is not an edit and gets the same 422 an empty body does.
     *
     * This is also what forces the legacy column to be derived as "keep the
     * current value while it survives" rather than "first of the array" — the
     * latter would dirty `users.branch_id` on a pure reorder and report a
     * change that is not one.
     */
    public function test_reordering_the_same_branches_is_not_a_change(): void
    {
        $user = User::factory()->inBranches($this->second, $this->third)->create();
        $user->assignRole('viewer');

        $this->putJson("/api/users/{$user->id}", [
            'branch_ids' => [$this->third->id, $this->second->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['changes']);

        $this->assertSame(
            $this->second->id,
            $user->fresh()->branch_id,
            'A refused reorder must not have moved the legacy column either.',
        );
    }

    public function test_repeating_the_same_branches_is_not_a_change(): void
    {
        $user = User::factory()->inBranches($this->second, $this->third)->create();
        $user->assignRole('viewer');

        $this->putJson("/api/users/{$user->id}", [
            'branch_ids' => [$this->second->id, $this->third->id, $this->second->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['changes']);
    }

    /**
     * Dropping a branch is a change, in the direction the reorder case is not.
     */
    public function test_removing_a_branch_is_a_change(): void
    {
        $user = User::factory()->inBranches($this->second, $this->third)->create();
        $user->assignRole('viewer');

        $this->putJson("/api/users/{$user->id}", [
            'branch_ids' => [$this->third->id],
        ])->assertOk();

        $this->assertSame([$this->third->id], $this->branchIdsOf($user));
        $this->assertSame(
            $this->third->id,
            $user->fresh()->branch_id,
            'The legacy column named a branch the user has just been removed from.',
        );
    }

    /**
     * The legacy edit, unchanged: a different `branch_id` reassigns the user.
     */
    public function test_branch_id_alone_still_reassigns_the_user(): void
    {
        $user = User::factory()->create(['branch_id' => $this->second->id]);
        $user->assignRole('viewer');

        $this->putJson("/api/users/{$user->id}", [
            'branch_id' => $this->third->id,
        ])->assertOk();

        $this->assertSame($this->third->id, $user->fresh()->branch_id);
        $this->assertSame(
            [$this->third->id],
            $this->branchIdsOf($user),
            'The legacy field has to move the pivot too, or the two shapes disagree.',
        );
    }

    /**
     * A screen that cannot render more than one branch must not be able to
     * delete the ones it cannot see.
     *
     * The old user form posts the whole record back on save. For a user
     * assigned to {second, third} that means `branch_id: second` — the value it
     * was shown. Reading that as "narrow this user to second" would have every
     * unrelated save from a not-yet-updated client silently strip branches.
     */
    public function test_reposting_the_current_branch_id_leaves_extra_branches_alone(): void
    {
        $user = User::factory()->inBranches($this->second, $this->third)->create();
        $user->assignRole('viewer');

        $this->putJson("/api/users/{$user->id}", [
            'first_name' => 'Renamed',
            'branch_id' => $this->second->id,
        ])->assertOk();

        $this->assertSame('Renamed', $user->fresh()->first_name);
        $this->assertSame([$this->second->id, $this->third->id], $this->branchIdsOf($user));
    }

    /**
     * When both shapes arrive, the array is the instruction and the scalar is
     * ignored — including when they disagree.
     */
    public function test_branch_ids_wins_when_both_shapes_arrive(): void
    {
        $user = User::factory()->create(['branch_id' => $this->second->id]);
        $user->assignRole('viewer');

        $this->putJson("/api/users/{$user->id}", [
            'branch_id' => $this->second->id,
            'branch_ids' => [$this->third->id],
        ])->assertOk();

        $this->assertSame([$this->third->id], $this->branchIdsOf($user));
        $this->assertSame($this->third->id, $user->fresh()->branch_id);
    }

    /**
     * Adding a branch keeps the primary where it is, so the singular shape an
     * old session renders does not move under it.
     */
    public function test_the_legacy_column_keeps_its_branch_while_that_branch_survives(): void
    {
        $user = User::factory()->create(['branch_id' => $this->third->id]);
        $user->assignRole('viewer');

        $this->putJson("/api/users/{$user->id}", [
            'branch_ids' => [$this->second->id, $this->third->id],
        ])->assertOk();

        $this->assertSame($this->third->id, $user->fresh()->branch_id);
        $this->assertSame([$this->second->id, $this->third->id], $this->branchIdsOf($user));
    }

    public function test_clearing_every_branch_is_refused(): void
    {
        $user = User::factory()->create(['branch_id' => $this->second->id]);
        $user->assignRole('viewer');

        $this->putJson("/api/users/{$user->id}", ['branch_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch_ids']);

        $this->assertSame([$this->second->id], $this->branchIdsOf($user));
    }

    /**
     * A branch-only edit leaves the users row clean whenever the primary
     * survives, so the Auditable trait never fires — the same gap `role` has,
     * and the reason both are logged by hand.
     */
    public function test_a_branch_change_is_audited(): void
    {
        $user = User::factory()->create(['branch_id' => $this->second->id]);
        $user->assignRole('viewer');

        $this->putJson("/api/users/{$user->id}", [
            'branch_ids' => [$this->second->id, $this->third->id],
        ])->assertOk();

        $log = AuditLog::where('action', 'branches_changed')
            ->where('auditable_id', $user->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'A branch reassignment left no trace in the audit trail.');
        $this->assertSame([$this->second->id], $log->old_values['branch_ids']);
        $this->assertSame([$this->second->id, $this->third->id], $log->new_values['branch_ids']);
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * `?branch_id=` has to find a user assigned to that branch SECOND, which is
     * the whole population this feature creates. Filtering on `users.branch_id`
     * would silently hide them, and the list and its `meta.stats` have to agree
     * about it.
     */
    public function test_the_branch_filter_finds_users_assigned_to_a_branch_second(): void
    {
        $user = User::factory()->inBranches($this->second, $this->third)->create();
        $user->assignRole('viewer');

        $response = $this->getJson("/api/users?branch_id={$this->third->id}")->assertOk();

        $this->assertContains($user->id, array_column($response->json('data'), 'id'));
        $this->assertSame(1, $response->json('meta.stats.active'));
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * `UserFactory` sets `branch_id` as a mass-assignment attribute, which
     * under a pivot fills in only half of an assignment and fails silently.
     * ~30 call sites depend on the default and 47 pass an explicit branch.
     */
    public function test_the_factory_puts_generated_users_in_the_pivot(): void
    {
        $this->assertSame(
            [$this->branch->id],
            $this->branchIdsOf(User::factory()->create()),
            'The factory default left the user branchless in the new shape.',
        );

        $this->assertSame(
            [$this->second->id],
            $this->branchIdsOf(User::factory()->create(['branch_id' => $this->second->id])),
        );

        $this->assertSame(
            [],
            $this->branchIdsOf(User::factory()->create(['branch_id' => null])),
        );

        $multi = User::factory()->inBranches($this->second, $this->third)->create();

        $this->assertSame([$this->second->id, $this->third->id], $this->branchIdsOf($multi));
        $this->assertSame($this->second->id, $multi->branch_id);
    }

    /**
     * The seeded super admin is the account ~90 test classes act as. If the
     * seeder wrote only the column it would be the one user in the baseline
     * with no branches.
     */
    public function test_the_seeded_admin_is_in_the_pivot(): void
    {
        $this->assertSame([$this->branch->id], $this->branchIdsOf($this->admin));
    }

    // -----------------------------------------------------------------

    /**
     * Branch ids out of a serialised `branches` array, sorted.
     *
     * Sorted because the ORDER of a many-to-many result is not part of the
     * assignment — the API never promises one, and a test that depends on the
     * order MySQL happens to return rows in is a test that fails on a rebuild
     * rather than on a regression.
     *
     * @param  list<array<string, mixed>>  $branches
     * @return list<int>
     */
    private function sortedIds(array $branches): array
    {
        $ids = array_map(intval(...), array_column($branches, 'id'));
        sort($ids);

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function branchIdsOf(User $user): array
    {
        return $user->branches()
            ->orderBy('branches.id')
            ->pluck('branches.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newUser(array $overrides = []): array
    {
        return [
            'first_name' => 'Multi',
            'last_name' => 'Branch',
            'username' => 'multi',
            'email' => 'multi@lendyph.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'branch_id' => $this->branch->id,
            'role' => 'loan_officer',
            ...$overrides,
        ];
    }

    /**
     * A fresh instance of the real migration, so the backfill under test is the
     * one that will run on the live databases rather than a copy of its SQL.
     */
    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_20_100000_create_branch_user_table.php');
    }
}
