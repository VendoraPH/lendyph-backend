<?php

namespace Database\Factories;

use App\Models\Borrower;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

class BorrowerFactory extends Factory
{
    protected $model = Borrower::class;

    public function definition(): array
    {
        return [
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'middle_name' => $this->faker->optional()->lastName(),
            'birthdate' => $this->faker->date('Y-m-d', '-20 years'),
            'civil_status' => $this->faker->randomElement(['single', 'married', 'widowed']),
            'gender' => $this->faker->randomElement(['male', 'female']),
            'address' => $this->faker->address(),
            'contact_number' => $this->faker->phoneNumber(),
            'email' => $this->faker->unique()->safeEmail(),
            'employer_or_business' => $this->faker->company(),
            'monthly_income' => $this->faker->randomFloat(2, 10000, 80000),
            'branch_id' => Branch::factory(),
            'status' => 'active',
        ];
    }
}
