<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\Repayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RepaymentFactory extends Factory
{
    protected $model = Repayment::class;

    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory(),
            'payment_date' => now()->toDateString(),
            'amount_paid' => 5000,
            'principal_applied' => 4000,
            'interest_applied' => 1000,
            'penalty_applied' => 0,
            'overpayment' => 0,
            'payment_type' => 'exact',
            'status' => 'posted',
            'received_by' => User::factory(),
        ];
    }
}
