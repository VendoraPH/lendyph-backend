<?php

namespace Tests\Feature\IntegrityRound;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Item 10: RoleController::update() and destroy() act on the role they loaded
 * BEFORE taking the role locks, and Spatie's permission cache is cleared
 * before the transaction commits.
 *
 * A real race needs two connections. These tests reproduce what the losing
 * request sees: the route binding loads the role, then (from a `retrieved`
 * hook on that first load) the row is removed, as a delete that committed
 * while this request waited for the lock would have removed it.
 *
 *  - Double-submitted delete: the second one writes a second `deleted` audit
 *    row (and a second `role_permissions_changed`) for a role already gone,
 *    and answers 200.
 *  - An update queued behind a delete: `update()` writes a phantom `updated`
 *    row, and `syncPermissions()` inserts pivot rows for a deleted role id
 *    (FK failure, 500).
 *
 * Pinned (plan decision 3): both re-read the role under the lock and answer
 * 409 when it is gone, writing nothing.
 *
 * The cache: syncPermissions() and the model's `saved` hook clear the
 * permission cache while the transaction is still open. A request in between
 * rebuilds the cache from the committed (old) rows and keeps it for 24 hours
 * (config/permission.php). Pinned mechanism: the cache key is also forgotten
 * once the role write has committed (DB::afterCommit), checked here as a
 * forget at the transaction level the request started at.
 */
class RoleWriteRaceTest extends TestCase
{
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::where('username', 'super_admin')->firstOrFail();
        $this->actingAs($this->actor);
    }

    private function customRole(): Role
    {
        $role = Role::create(['name' => 'night_desk', 'guard_name' => 'web', 'is_system' => false, 'is_active' => true]);
        $role->syncPermissions(['dashboard:view']);

        return $role;
    }

    /**
     * Remove the role's row right after its first load in the next request
     * (the route binding), as a concurrent delete that committed first would.
     */
    private function deleteRowAfterBinding(Role $role): void
    {
        $done = false;

        Role::retrieved(function (Role $loaded) use ($role, &$done): void {
            if ($done || $loaded->getKey() !== $role->getKey()) {
                return;
            }

            $done = true;
            DB::table('roles')->where('id', $role->id)->delete();
        });
    }

    private function auditRowsFor(Role $role, string $action): int
    {
        return AuditLog::query()
            ->where('auditable_type', $role->getMorphClass())
            ->where('auditable_id', $role->id)
            ->where('action', $action)
            ->count();
    }

    public function test_a_delete_of_a_role_already_deleted_answers_409_and_audits_nothing(): void
    {
        $role = $this->customRole();
        $this->deleteRowAfterBinding($role);

        $this->deleteJson("/api/roles/{$role->id}")->assertStatus(409);

        $this->assertSame(0, $this->auditRowsFor($role, 'deleted'));
        $this->assertSame(0, $this->auditRowsFor($role, 'role_permissions_changed'));
    }

    public function test_an_update_of_a_role_deleted_meanwhile_answers_409_and_audits_nothing(): void
    {
        $role = $this->customRole();
        $this->deleteRowAfterBinding($role);

        $this->putJson("/api/roles/{$role->id}", [
            'display_name' => 'Night Desk',
            'permissions' => ['dashboard:view', 'loans:view'],
        ])->assertStatus(409);

        $this->assertSame(0, $this->auditRowsFor($role, 'updated'));
        $this->assertSame(0, $this->auditRowsFor($role, 'role_permissions_changed'));
        $this->assertSame(0, DB::table('role_has_permissions')->where('role_id', $role->id)->count());
    }

    public function test_a_permission_change_clears_the_permission_cache_after_it_commits(): void
    {
        $role = $this->customRole();
        $base = DB::transactionLevel();

        $levels = [];
        $key = config('permission.cache.key');
        Event::listen(KeyForgotten::class, function (KeyForgotten $event) use ($key, &$levels): void {
            if ($event->key === $key) {
                $levels[] = DB::transactionLevel();
            }
        });

        $this->putJson("/api/roles/{$role->id}", [
            'permissions' => ['dashboard:view', 'loans:view'],
        ])->assertSuccessful();

        $this->assertContains($base, $levels, 'the permission cache should be cleared once the change has committed');
    }

    public function test_deactivating_a_role_clears_the_permission_cache_after_it_commits(): void
    {
        $role = $this->customRole();
        $base = DB::transactionLevel();

        $levels = [];
        $key = config('permission.cache.key');
        Event::listen(KeyForgotten::class, function (KeyForgotten $event) use ($key, &$levels): void {
            if ($event->key === $key) {
                $levels[] = DB::transactionLevel();
            }
        });

        $this->patchJson("/api/roles/{$role->id}/deactivate")->assertSuccessful();

        $this->assertContains($base, $levels, 'the permission cache should be cleared once the change has committed');
    }
}
