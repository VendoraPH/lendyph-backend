<?php

namespace Tests\Feature\Cda\Foundation;

use App\Models\CdaRegulatoryProfile;
use App\Models\Role;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared set-up for the CDA foundation tests: users whose only role holds
 * exactly the permissions named (never super_admin), and a regulatory
 * profile with or without a valid Certificate of Tax Exemption.
 */
trait CdaTestHelpers
{
    private int $probeRoles = 0;

    /** @param  list<string>  $permissions */
    protected function userWith(array $permissions): User
    {
        $this->probeRoles++;
        $role = Role::create(['name' => 'cda_probe_'.$this->probeRoles.'_'.Role::query()->count(), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    protected function withCte(string $status = 'valid', ?string $expiresOn = null): void
    {
        CdaRegulatoryProfile::current()->update(['cte_status' => $status, 'cte_number' => 'CTE-2025-0001', 'cte_expires_on' => $expiresOn]);
    }
}
