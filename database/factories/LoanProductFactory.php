<?php

namespace Database\Factories;

use App\Models\LoanProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

class LoanProductFactory extends Factory
{
    protected $model = LoanProduct::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true).' Loan',
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'processing_fee' => 2.0,
            'service_fee' => 1.0,
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
            'min_amount' => 5000,
            'max_amount' => 500000,
            'status' => 'active',
        ];
    }

    public function diminishing(): static
    {
        return $this->state(['interest_method' => 'diminishing']);
    }

    public function uponMaturity(): static
    {
        return $this->state(['interest_method' => 'upon_maturity']);
    }
}
