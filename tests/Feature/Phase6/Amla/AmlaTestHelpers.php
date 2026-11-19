<?php

namespace Tests\Feature\Phase6\Amla;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared set-up for the AMLA foundation tests: users whose only role holds
 * exactly the permissions named (never super_admin), in the seeded branch.
 */
trait AmlaTestHelpers
{
    private int $amlaProbeRoles = 0;

    /** @param  list<string>  $permissions */
    protected function amlaUser(array $permissions): User
    {
        $this->amlaProbeRoles++;
        $role = Role::create(['name' => 'amla_probe_'.$this->amlaProbeRoles.'_'.Role::query()->count(), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /**
     * The row counts AMLA must never change: the main audit trail and staff
     * notices (AMLA design §3.6, §4.14). Take them before an AMLA flow and
     * assert they are the same after it; later AMLA slices copy this.
     *
     * @return array{audit_logs: int, notifications: int}
     */
    protected function outsideAmlaCounts(): array
    {
        return [
            'audit_logs' => DB::table('audit_logs')->count(),
            'notifications' => DB::table('notifications')->count(),
        ];
    }

    /** @param  array{audit_logs: int, notifications: int}  $before */
    protected function assertNothingWrittenOutsideAmla(array $before): void
    {
        $this->assertSame($before, $this->outsideAmlaCounts(), 'AMLA wrote to the main audit trail or sent a staff notice.');
    }

    /** A head-office settings holder: `amla:settings`, `amla:view` and `branches:view_all`. */
    protected function settingsHolder(): User
    {
        return $this->amlaUser(['amla:view', 'amla:settings', 'branches:view_all']);
    }
}
