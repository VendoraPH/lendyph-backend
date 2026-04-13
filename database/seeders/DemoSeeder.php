<?php

namespace Database\Seeders;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Fee;
use App\Models\LoanProduct;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\LoanService;
use App\Services\RepaymentService;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    /**
     * Seed realistic demo data for frontend testing.
     * Run with: php artisan db:seed --class=DemoSeeder
     *
     * Creates: 2 loan products, 8 borrowers, 5 loans (various statuses),
     * repayments, co-makers, fees, share capital pledges + ledger entries,
     * and 3 users with different roles.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('DemoSeeder must not run in production.');

            return;
        }

        $this->command->info('Seeding demo data...');

        $loanService = app(LoanService::class);
        $branch = Branch::first();

        // ── Users ──
        $officer = User::firstOrCreate(['username' => 'officer'], [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'officer@lendyph.com',
            'password' => 'password',
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);
        $officer->syncRoles(['loan_officer']);

        $cashier = User::firstOrCreate(['username' => 'cashier'], [
            'first_name' => 'Pedro',
            'last_name' => 'Reyes',
            'email' => 'cashier@lendyph.com',
            'password' => 'password',
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);
        $cashier->syncRoles(['cashier']);

        $viewer = User::firstOrCreate(['username' => 'viewer'], [
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'email' => 'viewer@lendyph.com',
            'password' => 'password',
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);
        $viewer->syncRoles(['viewer']);

        // ── Loan Products ──
        $salaryLoan = LoanProduct::firstOrCreate(['name' => 'Salary Loan'], [
            'interest_rate' => 3.0,
            'min_interest_rate' => 2.0,
            'interest_method' => 'straight',
            'term' => 6,
            'min_term' => 3,
            'max_term' => 12,
            'frequency' => 'monthly',
            'frequencies' => ['monthly', 'semi_monthly'],
            'processing_fee' => 2.0,
            'service_fee' => 1.0,
            'penalty_rate' => 3.0,
            'grace_period_days' => 3,
            'scb_required' => true,
            'min_scb' => 100,
            'max_scb' => 500,
            'min_amount' => 5000,
            'max_amount' => 100000,
        ]);

        $businessLoan = LoanProduct::firstOrCreate(['name' => 'Business Loan'], [
            'interest_rate' => 2.5,
            'min_interest_rate' => 1.5,
            'interest_method' => 'diminishing',
            'term' => 12,
            'min_term' => 6,
            'max_term' => 24,
            'frequency' => 'monthly',
            'frequencies' => ['monthly'],
            'processing_fee' => 2.5,
            'service_fee' => 1.5,
            'penalty_rate' => 2.0,
            'grace_period_days' => 5,
            'scb_required' => false,
            'min_scb' => 0,
            'max_scb' => 1000,
            'min_amount' => 20000,
            'max_amount' => 500000,
        ]);

        // ── Fees ──
        Fee::firstOrCreate(['name' => 'Processing Fee'], ['type' => 'percentage', 'value' => 2.0]);
        Fee::firstOrCreate(['name' => 'Service Fee'], ['type' => 'percentage', 'value' => 1.0]);
        Fee::firstOrCreate(['name' => 'Notarial Fee'], ['type' => 'fixed', 'value' => 500]);

        // ── Borrowers ──
        $borrowers = collect([
            ['first_name' => 'Rosario', 'middle_name' => 'D.', 'last_name' => 'Santos', 'gender' => 'female', 'civil_status' => 'married', 'contact_number' => '09171234567', 'monthly_income' => 25000, 'employer_or_business' => 'Sari-sari Store', 'spouse_first_name' => 'Ricardo', 'spouse_last_name' => 'Santos'],
            ['first_name' => 'Roberto', 'last_name' => 'Garcia', 'gender' => 'male', 'civil_status' => 'single', 'contact_number' => '09181234567', 'monthly_income' => 35000, 'employer_or_business' => 'Garcia Trading'],
            ['first_name' => 'Eduardo', 'last_name' => 'Mendoza', 'gender' => 'male', 'civil_status' => 'married', 'contact_number' => '09191234567', 'monthly_income' => 40000, 'employer_or_business' => 'Mendoza Construction', 'spouse_first_name' => 'Gloria', 'spouse_last_name' => 'Mendoza'],
            ['first_name' => 'Maria', 'middle_name' => 'L.', 'last_name' => 'Reyes', 'gender' => 'female', 'civil_status' => 'widowed', 'contact_number' => '09201234567', 'monthly_income' => 20000, 'employer_or_business' => 'Reyes Bakeshop'],
            ['first_name' => 'Ana', 'last_name' => 'Santos', 'gender' => 'female', 'civil_status' => 'single', 'contact_number' => '09211234567', 'monthly_income' => 30000, 'employer_or_business' => 'BPO Company'],
            ['first_name' => 'Carmen', 'last_name' => 'Torres', 'gender' => 'female', 'civil_status' => 'married', 'contact_number' => '09221234567', 'monthly_income' => 18000, 'employer_or_business' => 'Freelancer', 'spouse_first_name' => 'Jose', 'spouse_last_name' => 'Torres'],
            ['first_name' => 'Danilo', 'last_name' => 'Villanueva', 'gender' => 'male', 'civil_status' => 'single', 'contact_number' => '09231234567', 'monthly_income' => 45000, 'employer_or_business' => 'IT Solutions'],
            ['first_name' => 'Lorna', 'middle_name' => 'M.', 'last_name' => 'Bautista', 'gender' => 'female', 'civil_status' => 'single', 'contact_number' => '09241234567', 'monthly_income' => 22000, 'employer_or_business' => 'Clinic Assistant'],
        ])->map(function ($data) use ($branch) {
            return Borrower::firstOrCreate(
                ['first_name' => $data['first_name'], 'last_name' => $data['last_name']],
                array_merge($data, ['branch_id' => $branch->id, 'address' => 'Butuan City, Agusan del Norte', 'status' => 'active']),
            );
        });

        $admin = User::where('username', 'admin')->first();

        // ── Loans in various statuses ──
        // Loan 1: Released + ongoing (Rosario, salary loan, 3 months ago)
        $loan1 = $loanService->createLoan([
            'borrower_id' => $borrowers[0]->id,
            'loan_product_id' => $salaryLoan->id,
            'principal_amount' => 50000,
            'interest_rate' => 3.0,
            'start_date' => now()->subMonths(3)->toDateString(),
            'purpose' => 'Business expansion',
        ], $admin);
        $loanService->submitForReview($loan1);
        $loanService->approve($loan1, $admin, 'Good track record');
        $loanService->release($loan1, $admin);

        // Make 2 payments to transition to ongoing
        $repaymentService = app(RepaymentService::class);
        $schedule = $loan1->amortizationSchedules()->orderBy('period_number')->first();
        if ($schedule) {
            $repaymentService->processRepayment($loan1, (float) $schedule->total_due, now()->subMonths(2)->toDateString(), $admin, 'First payment', 'cash');
            $schedule2 = $loan1->amortizationSchedules()->where('period_number', 2)->first();
            if ($schedule2) {
                $repaymentService->processRepayment($loan1, (float) $schedule2->total_due, now()->subMonth()->toDateString(), $admin, 'Second payment', 'gcash', 'GC-DEMO-001');
            }
        }

        // Loan 2: Draft (Roberto, business loan)
        $loanService->createLoan([
            'borrower_id' => $borrowers[1]->id,
            'loan_product_id' => $businessLoan->id,
            'principal_amount' => 100000,
            'interest_rate' => 2.5,
            'start_date' => now()->toDateString(),
            'purpose' => 'Working capital',
        ], $admin);

        // Loan 3: For review (Eduardo)
        $loan3 = $loanService->createLoan([
            'borrower_id' => $borrowers[2]->id,
            'loan_product_id' => $salaryLoan->id,
            'principal_amount' => 30000,
            'interest_rate' => 3.0,
            'start_date' => now()->toDateString(),
            'purpose' => 'Home improvement',
        ], $admin);
        $loanService->submitForReview($loan3);

        // Loan 4: Approved awaiting release (Maria)
        $loan4 = $loanService->createLoan([
            'borrower_id' => $borrowers[3]->id,
            'loan_product_id' => $salaryLoan->id,
            'principal_amount' => 20000,
            'interest_rate' => 3.0,
            'start_date' => now()->toDateString(),
            'purpose' => 'Tuition',
        ], $admin);
        $loanService->submitForReview($loan4);
        $loanService->approve($loan4, $admin, 'Verified income');

        // Loan 5: Released, old and overdue (Danilo, 6 months ago, no payments)
        $loan5 = $loanService->createLoan([
            'borrower_id' => $borrowers[6]->id,
            'loan_product_id' => $businessLoan->id,
            'principal_amount' => 80000,
            'interest_rate' => 2.5,
            'start_date' => now()->subMonths(6)->toDateString(),
            'purpose' => 'Equipment purchase',
        ], $admin);
        $loanService->submitForReview($loan5);
        $loanService->approve($loan5, $admin, 'OK');
        $loanService->release($loan5, $admin);

        // ── Share Capital Pledges (defaults auto-created by Borrower model event) ──
        foreach ($borrowers->take(6) as $i => $borrower) {
            $borrower->shareCapitalPledge->update([
                'amount' => ($i + 1) * 250,
                'auto_credit' => $i < 4,
            ]);
        }

        // ── Share Capital Ledger Entries ──
        foreach ($borrowers->take(4) as $borrower) {
            ShareCapitalLedger::firstOrCreate(
                ['borrower_id' => $borrower->id, 'description' => 'Initial share capital contribution'],
                ['date' => now()->subMonths(3)->toDateString(), 'credit' => 2000, 'debit' => 0, 'created_by' => $admin->id],
            );

            ShareCapitalLedger::firstOrCreate(
                ['borrower_id' => $borrower->id, 'description' => 'Monthly pledge - auto-credit'],
                ['date' => now()->subMonths(2)->toDateString(), 'credit' => 500, 'debit' => 0, 'created_by' => $admin->id],
            );
        }

        $this->command->info('Demo data seeded: 3 users, 2 products, 3 fees, 8 borrowers, 5 loans, 6 pledges, 8 ledger entries.');
    }
}
