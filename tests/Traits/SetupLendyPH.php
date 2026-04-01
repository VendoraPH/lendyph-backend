<?php

namespace Tests\Traits;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanService;
use Illuminate\Support\Facades\Artisan;

trait SetupLendyPH
{
    protected User $admin;
    protected Branch $branch;

    protected function seedAndLogin(): void
    {
        Artisan::call('migrate:fresh');
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->branch = Branch::first();
        $this->admin = User::where('username', 'admin')->first();
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
