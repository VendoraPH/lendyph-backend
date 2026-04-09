<?php

namespace Database\Factories;

use App\Models\Fee;
use Illuminate\Database\Eloquent\Factories\Factory;

class FeeFactory extends Factory
{
    protected $model = Fee::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true).' Fee',
            'type' => 'fixed',
            'value' => 500.0,
            'applicable_product_ids' => null,
            'conditions' => null,
        ];
    }

    public function fixed(): static
    {
        return $this->state(['type' => 'fixed']);
    }

    public function percentage(): static
    {
        return $this->state(['type' => 'percentage', 'value' => 2.5]);
    }
}
