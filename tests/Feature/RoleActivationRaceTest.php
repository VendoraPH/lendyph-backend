<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * deactivate() and reactivate() act on the role the route binding loaded. A
 * delete that committed while the request waited for the role locks leaves
 * that row gone; writing to it would audit an `updated` change that never
 * happened. Like update() and destroy() (RoleWriteRaceTest), both re-read the
 * role under the lock and answer 409, writing nothing, when it's gone.
 *
 * The row is removed from a `retrieved` hook on the route binding's load,
 * which is what the losing request sees.
 */
class RoleActivationRaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('username', 'super_admin')->firstOrFail());
    }

    private function customRole(bool $active): Role
    {
        $role = Role::create(['name' => 'night_desk', 'guard_name' => 'web', 'is_system' => false, 'is_active' => $active]);
        $role->syncPermissions(['dashboard:view']);

        return $role;
    }

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

    private function updatedRowsFor(Role $role): int
    {
        return AuditLog::query()
            ->where('auditable_type', $role->getMorphClass())
            ->where('auditable_id', $role->id)
            ->where('action', 'updated')
            ->count();
    }

    public function test_deactivating_a_role_deleted_meanwhile_answers_409_and_audits_nothing(): void
    {
        $role = $this->customRole(active: true);
        $this->deleteRowAfterBinding($role);

        $this->patchJson("/api/roles/{$role->id}/deactivate")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This role was changed or deleted by another request. Reload and try again.');

        $this->assertSame(0, $this->updatedRowsFor($role));
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_reactivating_a_role_deleted_meanwhile_answers_409_and_audits_nothing(): void
    {
        $role = $this->customRole(active: false);
        $this->deleteRowAfterBinding($role);

        $this->patchJson("/api/roles/{$role->id}/reactivate")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This role was changed or deleted by another request. Reload and try again.');

        $this->assertSame(0, $this->updatedRowsFor($role));
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }
}
