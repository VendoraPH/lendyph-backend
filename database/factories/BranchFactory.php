<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company().' Branch',
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'address' => $this->faker->address(),
            'contact_number' => $this->faker->phoneNumber(),
            'is_active' => true,
        ];
    }
}
