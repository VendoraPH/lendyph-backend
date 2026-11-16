<?php

namespace Tests\Feature\Linda;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\LoanService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The book Linda's answers are checked against: loans in the seeded Main
 * Branch and in a second branch, released far enough back that some of them
 * have a period past its grace, plus users with a chosen set of permissions.
 */
abstract class LindaAnswersTestCase extends TestCase
{
    use SetupLendyPH;

    protected Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        config()->set('linda.list_max', 10);
    }

    /**
     * A member in $branch with the given name.
     */
    protected function member(Branch $branch, string $first, string $last): Borrower
    {
        return Borrower::factory()->create([
            'branch_id' => $branch->id,
            'first_name' => $first,
            'middle_name' => null,
            'last_name' => $last,
        ]);
    }

    /**
     * A monthly loan released to $borrower in the member's branch on
     * $startDate, by default 2 months ago, which leaves one period past its
     * 3-day grace (a start 1 month and a day ago: one period due yesterday,
     * still inside it).
     */
    protected function releaseTo(Borrower $borrower, float $principal = 60000, ?string $startDate = null): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
        ]);

        $service = app(LoanService::class);
        $loan = $service->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'start_date' => $startDate ?? now()->subMonths(2)->toDateString(),
        ], $this->admin);

        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');
        $service->release($loan, $this->admin);

        // Released on its start date, as a loan with that history would be.
        $loan = $loan->fresh();
        $loan->update(['branch_id' => $borrower->branch_id, 'released_at' => $loan->start_date->copy()->setTime(9, 0)]);

        return $loan->fresh('amortizationSchedules', 'borrower');
    }

    protected function pay(Loan $loan, float $amount, ?string $date = null): void
    {
        $this->actingAs($this->admin);
        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => $date ?? now()->toDateString(),
            'amount_paid' => $amount,
            'method' => 'cash',
        ])->assertCreated();
    }

    /**
     * A user holding exactly $permissions, in $branches (none: no branch).
     *
     * @param  list<string>  $permissions
     */
    protected function userWith(array $permissions, Branch ...$branches): User
    {
        $role = Role::create(['name' => 'linda_'.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $factory = User::factory();
        $user = ($branches === [] ? $factory->state(['branch_id' => null]) : $factory->inBranches(...$branches))->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    /** A head-office user: every branch. */
    protected function headOffice(array $permissions = ['reports:view', 'loans:view', 'borrowers:view']): User
    {
        return $this->userWith([...$permissions, 'branches:view_all']);
    }

    /** A Main Branch user. */
    protected function branchUser(array $permissions = ['reports:view', 'loans:view', 'borrowers:view']): User
    {
        return $this->userWith($permissions, $this->branch);
    }

    /**
     * The report filters a user's own scope gives, as ReportController sets
     * them.
     *
     * @return array<string, mixed>
     */
    protected function scopeOf(User $user, array $filters = []): array
    {
        return [...$filters, 'branch_ids' => app(BranchAccess::class)->for($user)];
    }
}
