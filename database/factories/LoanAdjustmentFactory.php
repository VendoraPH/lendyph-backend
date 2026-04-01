<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LoanAdjustmentFactory extends Factory
{
    protected $model = LoanAdjustment::class;

    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory(),
            'adjustment_type' => 'penalty_waiver',
            'description' => $this->faker->sentence(),
            'old_values' => [],
            'new_values' => ['waive_all' => true],
            'status' => 'pending',
            'adjusted_by' => User::factory(),
        ];
    }
}
