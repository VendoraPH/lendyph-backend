<?php

namespace Tests\Feature\CreditScoring\Api;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use App\Services\CreditScoring\Recalculator;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CreditScoring\Engine\CreditFixtures;

/**
 * Callers, branches and scored members for the Credit Scoring API tests.
 * Every caller is a non-super_admin user, so each permission check is real.
 */
trait CreditApiFixtures
{
    use CreditFixtures;

    protected function mainBranch(): Branch
    {
        return Branch::query()->orderBy('id')->firstOrFail();
    }

    protected function farBranch(): Branch
    {
        return Branch::query()->where('code', 'FAR')->first()
            ?? Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
    }

    /**
     * A user whose only role holds exactly these permissions, assigned to
     * the given branches (the main branch when none are given).
     *
     * @param  list<string>  $permissions
     */
    protected function caller(array $permissions, Branch ...$branches): User
    {
        $role = Role::create(['name' => 'spec:'.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $user = User::factory()->inBranches(...($branches === [] ? [$this->mainBranch()] : $branches))->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * Every credit scoring permission, with loan and member reads, in the
     * main branch only.
     */
    protected function branchAnalyst(string ...$extra): User
    {
        return $this->caller(array_merge(['credit_scoring:view', 'credit_scoring:override', 'credit_scoring:settings', 'loans:view', 'loans:update', 'borrowers:view'], $extra));
    }

    protected function headOffice(): User
    {
        return $this->branchAnalyst('branches:view_all');
    }

    protected function scoreNow(Borrower $member): int
    {
        return (int) app(Recalculator::class)->scoreMember($member->id, 'Nightly review')['credit_score_id'];
    }

    /**
     * A scored member with a little history, in `$branch`.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function scoredMember(?Branch $branch = null, array $attributes = []): Borrower
    {
        $member = $this->member(array_merge(['branch_id' => ($branch ?? $this->mainBranch())->id, 'monthly_income' => 40000], $attributes));
        $loan = $this->releasedLoan($member);
        $this->paidMonthly($loan, '2026-06-01', 3);
        $this->scoreNow($member);

        return $member;
    }

    /**
     * A draft loan application for the member, previewable by LoanService.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function application(Borrower $member, array $attributes = []): Loan
    {
        $product = LoanProduct::factory()->create(['interest_rate' => 0, 'term' => 6, 'frequency' => 'monthly']);

        return Loan::factory()->create(array_merge([
            'borrower_id' => $member->id,
            'branch_id' => $member->branch_id,
            'loan_product_id' => $product->id,
            'status' => 'draft',
            'principal_amount' => 30000,
            'interest_rate' => 0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'start_date' => '2026-11-10',
            'maturity_date' => '2027-05-10',
            'created_by' => User::query()->value('id'),
        ], $attributes))->fresh();
    }

    /**
     * Every column of every lending row a credit scoring endpoint must never
     * write: loans, schedules, approvals, payments and members.
     *
     * @return array<string, mixed>
     */
    protected function lendingSnapshot(): array
    {
        return collect(['loans', 'amortization_schedules', 'loan_approval_steps', 'repayments', 'repayment_allocations', 'borrowers', 'loan_adjustments'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])
            ->all();
    }
}
