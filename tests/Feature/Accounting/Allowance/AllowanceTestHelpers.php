<?php

namespace Tests\Feature\Accounting\Allowance;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Accounting\LoanAccounting\LoanAccountingTestHelpers;

/**
 * Aged loans (LoanAccountingTestHelpers), the allowance permission, and the
 * allowance endpoints.
 *
 * `accounting:allowance` and the `accountant` role belong to C1's migration
 * and seeder. Until C1 is on development they are created here, in each
 * test's own transaction, and never in app code.
 */
trait AllowanceTestHelpers
{
    use LoanAccountingTestHelpers;

    private function grantTheAllowancePermission(): void
    {
        Permission::findOrCreate('accounting:allowance', 'web');
        Role::findByName('admin', 'web')->givePermissionTo('accounting:allowance');

        Role::firstOrCreate(
            ['name' => 'accountant', 'guard_name' => 'web'],
            ['display_name' => 'Accountant', 'is_system' => true, 'is_active' => true],
        )->givePermissionTo(['accounting:view', 'accounting:allowance', 'branches:view_all']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** A user holding one role, with every branch assigned to them. */
    private function userInRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole($role);

        return $user;
    }

    private function today(): string
    {
        return Carbon::today()->toDateString();
    }

    /** @return array<string, mixed> */
    private function preview(?string $asOf = null): array
    {
        return $this->getJson('/api/accounting/allowance/preview?as_of='.($asOf ?? $this->today()))
            ->assertOk()
            ->json('data');
    }

    /** @return array<string, mixed> the preview row of one branch */
    private function branchRow(array $preview, Branch $branch): array
    {
        $row = collect($preview['branches'])->firstWhere('branch.id', $branch->id);
        $this->assertNotNull($row, "Branch {$branch->name} is missing from the preview.");

        return $row;
    }

    /** Post the run the preview for `$asOf` proposes. */
    private function postTheRun(?string $asOf = null): TestResponse
    {
        $asOf ??= $this->today();

        return $this->postJson('/api/accounting/allowance/postings', [
            'as_of' => $asOf,
            'expected_adjustment' => $this->preview($asOf)['totals']['adjustment'],
        ]);
    }
}
