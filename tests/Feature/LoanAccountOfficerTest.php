<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * PATCH /loans/{loan}/account-officer
 *
 * The loan page's "Assign / Change" account officer control used to go through
 * PUT /loans/{loan}, which never worked. It refuses every released loan, and
 * UpdateLoanRequest has no `account_officer_id` rule, so on a draft the value
 * was silently dropped while the page reported success.
 */
class LoanAccountOfficerTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        return tap(
            User::factory()->create($attributes),
            fn (User $user) => $user->assignRole(Role::where('name', $role)->first()),
        );
    }

    private function createDraftLoan(): Loan
    {
        return app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => LoanProduct::factory()->create()->id,
            'principal_amount' => 20000,
            'start_date' => now()->toDateString(),
        ], $this->admin);
    }

    public function test_it_reassigns_the_officer_of_a_released_loan(): void
    {
        $loan = $this->createReleasedLoan();
        $officer = $this->userWithRole('loan_officer');

        $this->patchJson("/api/loans/{$loan->id}/account-officer", ['account_officer_id' => $officer->id])
            ->assertOk()
            ->assertJsonPath('data.id', $loan->id)
            ->assertJsonPath('data.account_officer_id', $officer->id)
            ->assertJsonPath('data.account_officer.id', $officer->id);

        $this->assertSame($officer->id, $loan->fresh()->account_officer_id);
    }

    public function test_the_general_update_still_refuses_a_released_loan(): void
    {
        $loan = $this->createReleasedLoan();
        $officer = $this->userWithRole('loan_officer');

        $this->putJson("/api/loans/{$loan->id}", ['account_officer_id' => $officer->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertNull($loan->fresh()->account_officer_id);
    }

    public function test_it_assigns_the_officer_of_a_draft_loan(): void
    {
        $loan = $this->createDraftLoan();
        $officer = $this->userWithRole('loan_officer');

        $this->patchJson("/api/loans/{$loan->id}/account-officer", ['account_officer_id' => $officer->id])
            ->assertOk()
            ->assertJsonPath('data.account_officer_id', $officer->id);

        $this->assertSame($officer->id, $loan->fresh()->account_officer_id);
    }

    public function test_it_records_who_moved_the_loan_and_from_whom(): void
    {
        $loan = $this->createReleasedLoan();
        $first = $this->userWithRole('loan_officer');
        $second = $this->userWithRole('loan_officer');

        $this->patchJson("/api/loans/{$loan->id}/account-officer", ['account_officer_id' => $first->id])->assertOk();
        $this->patchJson("/api/loans/{$loan->id}/account-officer", ['account_officer_id' => $second->id])->assertOk();

        $log = AuditLog::where('auditable_type', $loan->getMorphClass())
            ->where('auditable_id', $loan->id)
            ->where('action', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($first->id, $log->old_values['account_officer_id']);
        $this->assertSame(['account_officer_id'], array_values(array_diff(array_keys($log->new_values), ['updated_at'])));
        $this->assertSame($second->id, $log->new_values['account_officer_id']);
        $this->assertSame($this->admin->id, $log->user_id);
    }

    public function test_a_role_holding_loans_update_can_reassign(): void
    {
        $loan = $this->createReleasedLoan();
        $actor = $this->userWithRole('loan_officer');
        $officer = $this->userWithRole('loan_officer');

        $this->assertTrue($actor->can('loans:update'));
        $this->actingAs($actor);

        $this->patchJson("/api/loans/{$loan->id}/account-officer", ['account_officer_id' => $officer->id])
            ->assertOk();

        $this->assertSame($officer->id, $loan->fresh()->account_officer_id);
    }

    public function test_it_requires_loans_update(): void
    {
        $loan = $this->createReleasedLoan();
        $viewer = $this->userWithRole('viewer');
        $officer = $this->userWithRole('loan_officer');

        $this->assertFalse($viewer->can('loans:update'));
        $this->actingAs($viewer);

        $this->patchJson("/api/loans/{$loan->id}/account-officer", ['account_officer_id' => $officer->id])
            ->assertForbidden();

        $this->assertNull($loan->fresh()->account_officer_id);
    }

    public function test_it_refuses_an_inactive_user(): void
    {
        $loan = $this->createReleasedLoan();
        $gone = $this->userWithRole('loan_officer', ['status' => 'inactive']);

        $this->patchJson("/api/loans/{$loan->id}/account-officer", ['account_officer_id' => $gone->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_officer_id' => 'Choose an active user as the account officer.']);

        $this->assertNull($loan->fresh()->account_officer_id);
    }

    public function test_it_refuses_an_unknown_or_missing_officer(): void
    {
        $loan = $this->createReleasedLoan();

        $this->patchJson("/api/loans/{$loan->id}/account-officer", ['account_officer_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_officer_id']);

        $this->patchJson("/api/loans/{$loan->id}/account-officer", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_officer_id']);
    }
}
