<?php

namespace Database\Factories;

use App\Models\CdaOfficer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdaOfficer>
 */
class CdaOfficerFactory extends Factory
{
    protected $model = CdaOfficer::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'sex' => $this->faker->randomElement(CdaOfficer::SEXES),
            'position' => 'board_member',
            'term_start' => '2025-01-01',
            'term_end' => null,
            'status' => 'active',
        ];
    }
}
