<?php

namespace Tests\Feature\Accounting\LoanAccounting;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * Loans and periods built straight from the factories, with due dates counted
 * back from today, so each test states exactly how late every period is.
 */
trait LoanAccountingTestHelpers
{
    /** @var array<int, int> next free period number per loan */
    private array $periods = [];

    private int $accountSequence = 0;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function agedLoan(array $attributes = [], ?Borrower $member = null): Loan
    {
        $branchId = $attributes['branch_id'] ?? $this->branch->id;
        $member ??= Borrower::factory()->create(['branch_id' => $branchId]);

        return Loan::factory()->create(array_merge([
            'borrower_id' => $member->id,
            'branch_id' => $branchId,
            'status' => 'released',
            'grace_period_days' => 0,
            'created_by' => $this->admin->id,
            'loan_account_number' => sprintf('LN-%06d', 900000 + ++$this->accountSequence),
        ], $attributes));
    }

    /** A period due `$daysAgo` days before today (negative: in the future). */
    private function period(
        Loan $loan,
        int $daysAgo,
        float $principal = 0,
        float $interest = 0,
        float $penalty = 0,
        string $status = 'pending',
        float $principalPaid = 0,
    ): AmortizationSchedule {
        $period = ($this->periods[$loan->id] ?? 0) + 1;
        $this->periods[$loan->id] = $period;

        return AmortizationSchedule::factory()->create([
            'loan_id' => $loan->id,
            'period_number' => $period,
            'due_date' => Carbon::today()->subDays($daysAgo)->toDateString(),
            'principal_due' => $principal,
            'principal_paid' => $principalPaid,
            'interest_due' => $interest,
            'interest_paid' => 0,
            'penalty_amount' => $penalty,
            'penalty_paid' => 0,
            'total_due' => $principal + $interest,
            'remaining_balance' => 0,
            'status' => $status,
        ]);
    }

    /**
     * A user holding `$permissions` in `$branch` only (no branches:view_all).
     *
     * @param  list<string>  $permissions
     */
    private function branchUser(Branch $branch, array $permissions = ['accounting:view', 'loans:view']): User
    {
        $role = Role::firstOrCreate(['name' => 'loan_accounting_'.md5(implode(',', $permissions)), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $user = User::factory()->inBranches($branch)->create();
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, mixed> the loans list, as of today unless the query says otherwise */
    private function loans(string $query = ''): array
    {
        return $this->getJson('/api/accounting/loan-accounting/loans?per_page=100'.$query)
            ->assertOk()
            ->json();
    }

    /** @return array<string, array<string, mixed>> the list's rows keyed by account number */
    private function rowsByAccount(string $query = ''): array
    {
        return collect($this->loans($query)['data'])->keyBy('account_number')->all();
    }
}
