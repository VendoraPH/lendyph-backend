<?php

namespace Tests\Feature\FinishRunRound6;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 6: a short co-maker row (CoMakerResource::slim(), for a
 * caller who can't read the co-maker's record) says when the co-maker was
 * added to the loan (`added_at`), so the release dialog can remind a releaser
 * to reprint the promissory note for a co-maker added after it was printed.
 * `added_at` is the link's own time, not the co-maker's personal data.
 */
class SlimCoMakerAddedAtTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'probe_'.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_a_releaser_without_borrowers_view_gets_added_at_on_short_co_maker_rows(): void
    {
        $loan = Loan::factory()->create([
            'status' => 'approved',
            'branch_id' => $this->branch->id,
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id]),
            'loan_product_id' => LoanProduct::factory(),
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->userWith(['loans:view', 'loans:release']));
        $added = $this->postJson("/api/loans/{$loan->id}/co-makers", [
            'first_name' => 'Slim',
            'last_name' => 'Comaker',
            'address' => '9 Rizal Ave',
            'contact_number' => '09179998888',
        ])->assertCreated()->json('data');

        $this->assertArrayNotHasKey('contact_number', $added);
        $this->assertArrayHasKey('added_at', $added);
        $this->assertNotNull($added['added_at']);

        $row = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data.co_makers.0');
        $this->assertArrayNotHasKey('address', $row);
        $this->assertArrayNotHasKey('added_by', $row);

        $this->actingAs($this->admin);
        $full = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data.co_makers.0');
        $this->assertSame($full['added_at'], $row['added_at']);
        $this->assertSame($full['added_at'], $added['added_at']);
    }
}
