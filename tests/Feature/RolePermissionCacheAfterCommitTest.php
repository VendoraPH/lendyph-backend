<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The race behind RoleWriteRaceTest's cache tests, played out.
 *
 * Spatie clears its permission cache while a role write's transaction is still
 * open. Another request that checks a permission in that window rebuilds the
 * cache from the rows committed so far and keeps it for 24 hours, so the
 * change wouldn't take effect for anyone until it expired. Here, every clear
 * inside the transaction is followed at once by a rebuild, as that other
 * request would do; once the write commits, the cache must be empty again.
 */
class RolePermissionCacheAfterCommitTest extends TestCase
{
    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('username', 'super_admin')->firstOrFail());

        $this->role = Role::create(['name' => 'night_desk', 'guard_name' => 'web', 'is_system' => false, 'is_active' => true]);
        $this->role->syncPermissions(['dashboard:view']);
    }

    /**
     * Rebuild the cache right after each clear made inside a transaction
     * opened after this point, as a concurrent request would.
     */
    private function rebuildCacheAfterEachInTransactionClear(): void
    {
        $base = DB::transactionLevel();
        $key = config('permission.cache.key');

        Event::listen(KeyForgotten::class, function (KeyForgotten $event) use ($base, $key): void {
            if ($event->key === $key && DB::transactionLevel() > $base) {
                app(PermissionRegistrar::class)->getPermissions();
            }
        });
    }

    private function cacheHoldsPermissions(): bool
    {
        return app(PermissionRegistrar::class)->getCacheRepository()->has(config('permission.cache.key'));
    }

    public function test_a_cache_rebuilt_during_a_permission_change_is_cleared_once_it_commits(): void
    {
        $this->rebuildCacheAfterEachInTransactionClear();

        $this->putJson("/api/roles/{$this->role->id}", [
            'permissions' => ['dashboard:view', 'loans:view'],
        ])->assertSuccessful();

        $this->assertFalse($this->cacheHoldsPermissions());
    }

    public function test_a_cache_rebuilt_during_a_deactivation_is_cleared_once_it_commits(): void
    {
        $this->rebuildCacheAfterEachInTransactionClear();

        $this->patchJson("/api/roles/{$this->role->id}/deactivate")->assertSuccessful();

        $this->assertFalse($this->cacheHoldsPermissions());
    }

    public function test_a_cache_rebuilt_during_a_reactivation_is_cleared_once_it_commits(): void
    {
        $this->role->update(['is_active' => false]);
        $this->rebuildCacheAfterEachInTransactionClear();

        $this->patchJson("/api/roles/{$this->role->id}/reactivate")->assertSuccessful();

        $this->assertFalse($this->cacheHoldsPermissions());
    }
}
