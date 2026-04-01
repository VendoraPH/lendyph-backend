<?php

namespace Database\Factories;

use App\Models\Borrower;
use App\Models\CoMaker;
use Illuminate\Database\Eloquent\Factories\Factory;

class CoMakerFactory extends Factory
{
    protected $model = CoMaker::class;

    public function definition(): array
    {
        return [
            'borrower_id' => Borrower::factory(),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'address' => $this->faker->address(),
            'contact_number' => $this->faker->phoneNumber(),
            'occupation' => $this->faker->jobTitle(),
            'employer' => $this->faker->company(),
            'monthly_income' => $this->faker->randomFloat(2, 10000, 50000),
            'relationship_to_borrower' => $this->faker->randomElement(['spouse', 'sibling', 'parent', 'friend']),
            'status' => 'active',
        ];
    }
}
