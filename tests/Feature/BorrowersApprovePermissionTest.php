<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Role;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class BorrowersApprovePermissionTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_borrowers_approve_permission_exists_after_migrations(): void
    {
        $this->assertNotNull(
            Permission::where('name', 'borrowers:approve')->where('guard_name', 'web')->first(),
            "Expected permission 'borrowers:approve' to exist after migrations + seeders",
        );
    }

    public function test_admin_role_has_borrowers_approve(): void
    {
        $admin = Role::where('name', 'admin')->firstOrFail();

        $this->assertTrue(
            $admin->hasPermissionTo('borrowers:approve'),
            'Expected admin role to have borrowers:approve',
        );
    }

    public function test_loan_officer_role_has_borrowers_approve(): void
    {
        $loanOfficer = Role::where('name', 'loan_officer')->firstOrFail();

        $this->assertTrue(
            $loanOfficer->hasPermissionTo('borrowers:approve'),
            'Expected loan_officer role to have borrowers:approve',
        );
    }

    public function test_cashier_role_does_not_have_borrowers_approve(): void
    {
        $cashier = Role::where('name', 'cashier')->firstOrFail();

        $this->assertFalse(
            $cashier->hasPermissionTo('borrowers:approve'),
            'Cashier must NOT have borrowers:approve (membership approval is admin/loan_officer territory)',
        );
    }

    public function test_user_without_borrowers_approve_cannot_approve_registration(): void
    {
        // Build a user that has borrowers:update (and view) but NOT
        // borrowers:approve. Previously this same user could call the
        // endpoint via the borrowers:update gate; gating on the dedicated
        // approval permission locks that down.
        $user = User::factory()->create();
        $user->givePermissionTo(['borrowers:view', 'borrowers:update']);
        $this->actingAs($user);

        $borrower = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);

        $this->patchJson("/api/borrowers/{$borrower->id}/approve-registration")
            ->assertForbidden();

        $this->assertDatabaseHas('borrowers', [
            'id' => $borrower->id,
            'status' => 'pending',
            'approved_by' => null,
        ]);
    }

    public function test_user_without_borrowers_approve_cannot_reject_registration(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['borrowers:view', 'borrowers:update']);
        $this->actingAs($user);

        $borrower = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);

        $this->patchJson("/api/borrowers/{$borrower->id}/reject", ['reason' => 'unauthorized try'])
            ->assertForbidden();

        $this->assertDatabaseHas('borrowers', [
            'id' => $borrower->id,
            'status' => 'pending',
            'rejection_reason' => null,
        ]);
    }

    public function test_user_with_borrowers_approve_can_approve_registration(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['borrowers:view', 'borrowers:approve']);
        $this->actingAs($user);

        $borrower = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);

        $this->patchJson("/api/borrowers/{$borrower->id}/approve-registration")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.approved_by', $user->id);
    }
}
