<?php

namespace Database\Factories;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class LoanFactory extends Factory
{
    protected $model = Loan::class;

    public function definition(): array
    {
        return [
            'borrower_id' => Borrower::factory(),
            'loan_product_id' => LoanProduct::factory(),
            'branch_id' => Branch::factory(),
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'principal_amount' => 50000,
            'start_date' => Carbon::today(),
            'maturity_date' => Carbon::today()->addMonths(6),
            'deductions' => [],
            'total_deductions' => 0,
            'net_proceeds' => 50000,
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
            'status' => 'draft',
            'created_by' => User::factory(),
        ];
    }
}
