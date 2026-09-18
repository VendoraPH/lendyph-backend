<?php

namespace Tests\Traits;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanService;

trait SetupLendyPH
{
    protected User $admin;

    protected Branch $branch;

    /**
     * Grab the seeded baseline and authenticate as the super admin.
     *
     * The schema and the seed are Tests\TestCase's job now (one `migrate:fresh
     * --seeder` per worker process, each test wrapped in a transaction), so
     * this no longer rebuilds anything.
     */
    protected function seedAndLogin(): void
    {
        $this->branch = Branch::first();
        $this->admin = User::where('username', 'super_admin')->first();
        $this->actingAs($this->admin);
    }

    protected function createReleasedLoan(?array $overrides = []): Loan
    {
        $product = LoanProduct::factory()->create(array_merge([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
        ], $overrides['product'] ?? []));

        $borrower = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
        ]);

        $loanService = app(LoanService::class);

        $loan = $loanService->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $overrides['principal_amount'] ?? 60000,
            'start_date' => $overrides['start_date'] ?? now()->toDateString(),
        ], $this->admin);

        $loanService->submitForReview($loan);
        $loanService->approve($loan, $this->admin, 'Approved for testing');
        $loanService->release($loan, $this->admin);

        return $loan->fresh('amortizationSchedules', 'borrower', 'loanProduct');
    }
}
