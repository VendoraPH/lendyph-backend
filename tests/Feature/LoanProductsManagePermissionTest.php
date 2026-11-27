<?php

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Collateral;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Creating, editing and deleting loan products needs `loan_products:manage`,
 * held by admin and super_admin only. It used to ride on `loans:create` (create,
 * delete) and `loans:update` (edit), so every role that prepares loans could
 * rewrite the product catalogue.
 *
 * Granted by 2026_10_03_110000_add_loan_products_manage_permission on
 * already-migrated deployments, and by RoleAndPermissionSeeder on a fresh
 * database.
 */
uses(TestCase::class, SetupLendyPH::class);

beforeEach(function () {
    $this->seedAndLogin();
});

function loanProductsManageMigration(): object
{
    return require database_path('migrations/2026_10_03_110000_add_loan_products_manage_permission.php');
}

function loanProductsManageUserWithRole(string $role): User
{
    $user = User::factory()->create(['branch_id' => Branch::first()->id]);
    $user->assignRole(Role::where('name', $role)->firstOrFail());

    return $user;
}

/**
 * @return array<string, mixed>
 */
function loanProductsManageProductPayload(): array
{
    return [
        'name' => 'Narrowed Product',
        'interest_rate' => 3.0,
        'interest_method' => 'straight',
        'term' => 12,
        'frequency' => 'monthly',
    ];
}

/**
 * The roles holding the permission, by name.
 *
 * @return list<string>
 */
function rolesHoldingLoanProductsManage(): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return Role::whereHas('permissions', fn ($q) => $q->where('name', 'loan_products:manage'))
        ->orderBy('name')->pluck('name')->all();
}

it('seeds loan_products:manage for admin and super_admin only', function () {
    expect(Permission::where('name', 'loan_products:manage')->where('guard_name', 'web')->exists())->toBeTrue()
        ->and(rolesHoldingLoanProductsManage())->toBe(['admin', 'super_admin']);
});

it('lets loan_processor create a loan and attach collateral, but not manage products', function () {
    $processor = loanProductsManageUserWithRole('loan_processor');
    $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    $collateral = Collateral::factory()->create(['borrower_id' => $borrower->id]);
    $product = LoanProduct::factory()->create();
    $this->actingAs($processor);

    $loanId = $this->postJson('/api/loans', [
        'borrower_id' => $borrower->id,
        'loan_product_id' => $product->id,
        'principal_amount' => 20000,
        'start_date' => now()->toDateString(),
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/loans/{$loanId}/collaterals", [
        'collateral_id' => $collateral->id,
        'snapshot_value' => 50000,
    ])->assertCreated();
    $this->assertDatabaseHas('loan_collaterals', ['loan_id' => $loanId, 'collateral_id' => $collateral->id]);

    $this->getJson('/api/loan-products')->assertOk();
    $this->postJson('/api/loan-products', loanProductsManageProductPayload())->assertForbidden();
    $this->putJson("/api/loan-products/{$product->id}", ['name' => 'Renamed'])->assertForbidden();
    $this->deleteJson("/api/loan-products/{$product->id}")->assertForbidden();

    expect(LoanProduct::where('name', 'Narrowed Product')->exists())->toBeFalse()
        ->and($product->fresh()->name)->not->toBe('Renamed');
});

it('no longer lets loan_officer create, edit or delete a product', function () {
    $product = LoanProduct::factory()->create();
    $this->actingAs(loanProductsManageUserWithRole('loan_officer'));

    $this->getJson('/api/loan-products')->assertOk();
    $this->postJson('/api/loan-products', loanProductsManageProductPayload())->assertForbidden();
    $this->putJson("/api/loan-products/{$product->id}", ['name' => 'Renamed'])->assertForbidden();
    $this->deleteJson("/api/loan-products/{$product->id}")->assertForbidden();

    expect(LoanProduct::whereKey($product->id)->exists())->toBeTrue();
});

it('still lets admin and super_admin create, edit and delete a product', function (string $role) {
    $this->actingAs(loanProductsManageUserWithRole($role));

    $productId = $this->postJson('/api/loan-products', loanProductsManageProductPayload())
        ->assertCreated()->json('data.id');
    $this->putJson("/api/loan-products/{$productId}", ['name' => 'Renamed'])->assertOk();
    expect(LoanProduct::find($productId)->name)->toBe('Renamed');

    $this->deleteJson("/api/loan-products/{$productId}")->assertOk();
    expect(LoanProduct::whereKey($productId)->exists())->toBeFalse();
})->with(['admin', 'super_admin']);

it('adds the permission to admin and super_admin on an already-migrated box and nothing else', function () {
    Permission::where('name', 'loan_products:manage')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $before = DB::table('role_has_permissions')->count();

    loanProductsManageMigration()->up();

    expect(rolesHoldingLoanProductsManage())->toBe(['admin', 'super_admin'])
        ->and(DB::table('role_has_permissions')->count())->toBe($before + 2);
});

it('is harmless to run twice', function () {
    loanProductsManageMigration()->up();
    $rows = DB::table('role_has_permissions')->count();
    $permissions = DB::table('permissions')->count();

    loanProductsManageMigration()->up();

    expect(DB::table('role_has_permissions')->count())->toBe($rows)
        ->and(DB::table('permissions')->count())->toBe($permissions);
});

it('clears the permission cache so the grant applies at once', function () {
    Permission::where('name', 'loan_products:manage')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $admin = loanProductsManageUserWithRole('admin');
    expect($admin->fresh()->can('loan_products:manage'))->toBeFalse();

    loanProductsManageMigration()->up();

    expect($admin->fresh()->can('loan_products:manage'))->toBeTrue();
});

it('skips a role that does not exist on this box', function () {
    Permission::where('name', 'loan_products:manage')->delete();
    Role::where('name', 'admin')->firstOrFail()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    loanProductsManageMigration()->up();

    expect(rolesHoldingLoanProductsManage())->toBe(['super_admin']);
});

it('rolls back by removing the permission and its grants', function () {
    loanProductsManageMigration()->down();

    expect(Permission::where('name', 'loan_products:manage')->exists())->toBeFalse()
        ->and(rolesHoldingLoanProductsManage())->toBe([]);
});
