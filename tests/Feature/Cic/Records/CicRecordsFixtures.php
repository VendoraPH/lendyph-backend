<?php

namespace Tests\Feature\Cic\Records;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CicProductMapping;
use App\Models\CicSetting;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Loans, members and staff for the CIC records tests: a mapped product, a
 * member with every subject field, and a released loan with its schedule,
 * all built with factories at fixed dates.
 */
trait CicRecordsFixtures
{
    protected function cicOn(): void
    {
        config(['cic.enabled' => true, 'cic.test_captures' => false]);
        Carbon::setTestNow('2026-11-30 23:58:00');
        CicSetting::current()->forceFill(['provider_code' => 'LENDY001', 'is_mfi' => false])->save();
    }

    /** @param  list<string>  $permissions */
    protected function cicUser(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    protected function cicBranch(string $code = 'MAIN'): Branch
    {
        return Branch::factory()->create(['code' => $code]);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function cicMember(array $overrides = []): Borrower
    {
        return Borrower::factory()->create([
            'first_name' => 'Maria',
            'middle_name' => 'Santos',
            'last_name' => 'Cruz',
            'suffix' => null,
            'gender' => 'female',
            'birthdate' => '1985-04-12',
            'civil_status' => 'married',
            'spouse_first_name' => 'Jose',
            'spouse_last_name' => 'Cruz',
            'address' => '12 Rizal St',
            'street_address' => null,
            'barangay' => 'Poblacion',
            'city' => 'Naga',
            'province' => 'Camarines Sur',
            'contact_number' => '09171234567',
            'email' => 'maria@example.com',
            'employer_or_business' => 'Sari-sari store',
            'monthly_income' => '25000.50',
            'date_hired' => '2015-06-01',
            'status' => 'active',
            ...$overrides,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function cicProduct(string $recordType = 'CI', array $overrides = []): LoanProduct
    {
        $product = LoanProduct::factory()->create($overrides);

        CicProductMapping::factory()->create([
            'loan_product_id' => $product->id,
            'record_type' => $recordType,
            'contract_type' => $recordType === 'CI' ? '12' : '48',
            'transaction_type' => 'NA',
        ]);

        return $product;
    }

    /**
     * A released loan with `$periods` monthly periods of ₱10,000.00 principal
     * and ₱300.00 interest, the first due a month after release.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function cicLoan(?Borrower $member = null, ?LoanProduct $product = null, array $overrides = [], int $periods = 3, string $releasedAt = '2026-09-15 10:00:00'): Loan
    {
        $member ??= $this->cicMember();
        $product ??= $this->cicProduct();
        $released = Carbon::parse($releasedAt);

        $loan = Loan::factory()->create([
            'borrower_id' => $member->id,
            'loan_product_id' => $product->id,
            'branch_id' => $member->branch_id,
            'status' => 'ongoing',
            'frequency' => 'monthly',
            'principal_amount' => 10000 * $periods,
            'start_date' => $released->toDateString(),
            'maturity_date' => $released->copy()->addMonthsNoOverflow($periods)->toDateString(),
            'released_at' => $released,
            'grace_period_days' => 3,
            'loan_account_number' => 'LN-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            ...$overrides,
        ]);

        for ($n = 1; $n <= $periods; $n++) {
            AmortizationSchedule::factory()->create([
                'loan_id' => $loan->id,
                'period_number' => $n,
                'due_date' => $released->copy()->addMonthsNoOverflow($n)->toDateString(),
                'principal_due' => 10000,
                'interest_due' => 300,
                'total_due' => 10300,
                'remaining_balance' => 10000 * ($periods - $n),
                'status' => 'pending',
            ]);
        }

        return $loan->fresh();
    }
}
