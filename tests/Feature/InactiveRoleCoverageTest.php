<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use App\Rules\AssignableRole;
use App\Services\LoanApprovalChainService;
use App\Services\LoanService;
use App\Services\RolePermissionGrants;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A deactivated role grants nothing, everywhere a role's permissions are
 * read, and its users keep the assignment so reactivating restores access.
 * InactiveRoleTest pins the headline cases; these cover the rest.
 */
class InactiveRoleCoverageTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function role(string $name, array $permissions, bool $active = true): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web', 'is_system' => false, 'is_active' => $active]);
        $role->syncPermissions($permissions);

        return $role;
    }

    private function userWithRoles(Role|string ...$roles): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        foreach ($roles as $role) {
            $user->assignRole(is_string($role) ? Role::where('name', $role)->firstOrFail() : $role);
        }

        return User::findOrFail($user->id);
    }

    private function submittedLoan(): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
        ]);
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $service = app(LoanService::class);
        $loan = $service->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ], $this->admin);
        $service->submitForReview($loan, $this->admin);

        return $loan->fresh();
    }

    private function setActive(string $roleName, bool $active): void
    {
        $role = Role::where('name', $roleName)->firstOrFail();
        $this->actingAs($this->admin)
            ->patchJson("/api/roles/{$role->id}/".($active ? 'reactivate' : 'deactivate'))
            ->assertOk();
    }

    public function test_reactivating_a_role_restores_the_same_users_access_without_reassigning(): void
    {
        $role = $this->role('loan_viewer_desk', ['loans:view']);
        $user = $this->userWithRoles($role);
        $assignments = DB::table('model_has_roles')->where('role_id', $role->id)->count();

        $this->setActive('loan_viewer_desk', false);
        $this->actingAs(User::findOrFail($user->id))->getJson('/api/loans')->assertForbidden();
        $this->assertSame($assignments, DB::table('model_has_roles')->where('role_id', $role->id)->count());

        $this->setActive('loan_viewer_desk', true);
        $user = User::findOrFail($user->id);
        $this->actingAs($user)->getJson('/api/loans')->assertOk();
        $this->assertContains('loans:view', $this->actingAs($user)->getJson('/api/auth/me')->json('data.permissions'));
        $this->assertSame($assignments, DB::table('model_has_roles')->where('role_id', $role->id)->count());
    }

    public function test_can_act_is_false_while_the_steps_role_is_inactive(): void
    {
        $chain = app(LoanApprovalChainService::class);
        $step = new LoanApprovalStep(['role' => 'manager']);
        $manager = $this->userWithRoles('manager');

        $this->assertTrue($chain->canAct($step, $manager));

        $this->setActive('manager', false);
        $this->assertFalse($chain->canAct($step, User::findOrFail($manager->id)));

        $this->setActive('manager', true);
        $this->assertTrue($chain->canAct($step, User::findOrFail($manager->id)));
    }

    public function test_the_approval_steps_endpoint_answers_can_act_false_for_an_inactive_role(): void
    {
        $loan = $this->submittedLoan();
        // viewer keeps loans:view, so the steps can still be read.
        $user = $this->userWithRoles('manager', 'viewer');

        $this->actingAs($user)->getJson("/api/loans/{$loan->id}/approval-steps")
            ->assertOk()
            ->assertJsonPath('data.current_steps.1.role', 'manager')
            ->assertJsonPath('data.current_steps.1.can_act', true);

        $this->setActive('manager', false);
        $user = User::findOrFail($user->id);

        $steps = $this->actingAs($user)->getJson("/api/loans/{$loan->id}/approval-steps")
            ->assertOk()
            ->json('data.current_steps');
        $this->assertSame([false, false, false, false], array_column($steps, 'can_act'));

        $step = $loan->approvalSteps()->where('status', LoanApprovalStep::STATUS_PENDING)->firstOrFail();
        $this->actingAs($user)
            ->patchJson("/api/loans/{$loan->id}/approval-steps/{$step->id}/approve")
            ->assertForbidden();
    }

    public function test_only_the_active_roles_permissions_count_for_a_user_holding_both(): void
    {
        $active = $this->role('desk_active', ['users:view', 'users:update', 'loans:view']);
        $inactive = $this->role('desk_inactive', ['payments:view']);
        $actor = $this->userWithRoles($active, $inactive);
        $inactive->update(['is_active' => false]);
        $actor = User::findOrFail($actor->id);

        $this->assertEqualsCanonicalizing(
            ['users:view', 'users:update', 'loans:view'],
            $actor->getAllPermissions()->pluck('name')->all(),
        );
        $this->assertTrue($actor->hasPermissionTo('loans:view'));
        $this->assertFalse($actor->hasPermissionTo('payments:view'));
        $this->assertTrue($actor->hasRole('desk_inactive'), 'the assignment stays');

        // The grant guard reads the same list.
        $this->assertSame(['payments:view'], RolePermissionGrants::ungrantable($actor, ['loans:view', 'payments:view'], []));
    }

    public function test_assignable_role_judges_by_the_actors_active_roles_only(): void
    {
        $active = $this->role('desk_active', ['users:view', 'users:update', 'loans:view']);
        $inactive = $this->role('desk_inactive', ['payments:view']);
        $actor = $this->userWithRoles($active, $inactive);
        $inactive->update(['is_active' => false]);
        $actor = User::findOrFail($actor->id);

        $this->role('loan_reader', ['loans:view']);
        $this->role('payment_reader', ['payments:view']);

        $passes = function (string $roleName) use ($actor): bool {
            $passed = true;
            (new AssignableRole($actor))->validate('role', $roleName, function () use (&$passed): void {
                $passed = false;
            });

            return $passed;
        };

        $this->assertTrue($passes('loan_reader'));
        $this->assertFalse($passes('payment_reader'));

        $target = $this->userWithRoles('viewer');
        $this->actingAs($actor)
            ->putJson("/api/users/{$target->id}", ['role' => 'payment_reader'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
        $this->actingAs($actor)
            ->putJson("/api/users/{$target->id}", ['role' => 'loan_reader'])
            ->assertOk();
    }
}
