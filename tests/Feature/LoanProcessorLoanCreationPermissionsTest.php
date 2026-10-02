<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `loan_processor` prepares loan applications, so it needs to create a loan and
 * read what the new-loan form shows: the borrower, the fees, the collaterals
 * and the borrower's share capital.
 *
 * Granted by 2026_10_03_100000_grant_loan_processor_loan_creation_permissions
 * on already-migrated deployments, and by RoleAndPermissionSeeder on a fresh
 * database.
 */
uses(TestCase::class);

function loanProcessorCreationMigration(): object
{
    return require database_path('migrations/2026_10_03_100000_grant_loan_processor_loan_creation_permissions.php');
}

/**
 * @return list<string>
 */
function loanProcessorCreationGrants(): array
{
    return ['borrowers:view', 'collaterals:view', 'fees:view', 'loans:create', 'share_capital:view'];
}

/**
 * @return list<string>
 */
function loanProcessorPermissionNames(string $roleName = 'loan_processor'): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return Role::where('name', $roleName)->firstOrFail()
        ->permissions()->orderBy('name')->pluck('name')->all();
}

/**
 * Every role's permission names, keyed by role.
 *
 * @return array<string, list<string>>
 */
function loanProcessorEveryRolesPermissions(): array
{
    return Role::orderBy('name')->pluck('name')
        ->mapWithKeys(fn (string $roleName) => [$roleName => loanProcessorPermissionNames($roleName)])
        ->all();
}

/** The set loan_processor held on deployments before this change. */
function loanProcessorBeforeTheGrant(): void
{
    Role::where('name', 'loan_processor')->firstOrFail()->syncPermissions(['loans:view', 'loans:update']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

it('grants exactly the five permissions under their existing names', function () {
    $existing = Permission::where('guard_name', 'web')
        ->whereIn('name', loanProcessorCreationGrants())
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($existing)->toBe(loanProcessorCreationGrants());
});

it('seeds loan_processor with its chain permissions plus the creation set on a fresh database', function () {
    expect(loanProcessorPermissionNames())->toBe([
        'borrowers:view',
        'collaterals:view',
        'fees:view',
        'loans:create',
        'loans:update',
        'loans:view',
        'share_capital:view',
    ]);
});

it('adds the five permissions to an already-migrated loan_processor and nothing else', function () {
    loanProcessorBeforeTheGrant();
    $before = loanProcessorPermissionNames();

    loanProcessorCreationMigration()->up();

    $after = loanProcessorPermissionNames();
    expect(array_values(array_diff($after, $before)))->toBe(loanProcessorCreationGrants())
        ->and(array_diff($before, $after))->toBe([]);
});

it('leaves every other role exactly as it was', function () {
    loanProcessorBeforeTheGrant();
    $before = loanProcessorEveryRolesPermissions();

    loanProcessorCreationMigration()->up();

    $after = loanProcessorEveryRolesPermissions();
    unset($before['loan_processor'], $after['loan_processor']);

    expect($after)->toBe($before);
});

it('keeps a hand-made extra grant on loan_processor', function () {
    loanProcessorBeforeTheGrant();
    Role::where('name', 'loan_processor')->firstOrFail()->givePermissionTo('reports:view');

    loanProcessorCreationMigration()->up();

    expect(loanProcessorPermissionNames())->toContain('reports:view', 'loans:create');
});

it('is harmless to run twice', function () {
    loanProcessorBeforeTheGrant();

    loanProcessorCreationMigration()->up();
    $rows = DB::table('role_has_permissions')->count();
    $permissions = DB::table('permissions')->count();
    $granted = loanProcessorPermissionNames();

    loanProcessorCreationMigration()->up();

    expect(DB::table('role_has_permissions')->count())->toBe($rows)
        ->and(DB::table('permissions')->count())->toBe($permissions)
        ->and(loanProcessorPermissionNames())->toBe($granted);
});

it('clears the permission cache so the grant applies at once', function () {
    loanProcessorBeforeTheGrant();
    $user = User::factory()->create();
    $user->assignRole('loan_processor');
    expect($user->fresh()->can('loans:create'))->toBeFalse();

    loanProcessorCreationMigration()->up();

    expect($user->fresh()->can('loans:create'))->toBeTrue();
});

it('skips a box where loan_processor does not exist', function () {
    Role::where('name', 'loan_processor')->firstOrFail()->delete();
    $rows = DB::table('role_has_permissions')->count();

    loanProcessorCreationMigration()->up();

    expect(DB::table('role_has_permissions')->count())->toBe($rows)
        ->and(Role::where('name', 'loan_processor')->exists())->toBeFalse();
});

it('rolls back to the chain permissions only', function () {
    loanProcessorBeforeTheGrant();
    loanProcessorCreationMigration()->up();
    $others = loanProcessorEveryRolesPermissions();
    unset($others['loan_processor']);

    loanProcessorCreationMigration()->down();

    $after = loanProcessorEveryRolesPermissions();
    expect($after['loan_processor'])->toBe(['loans:update', 'loans:view']);
    unset($after['loan_processor']);
    expect($after)->toBe($others);
});
