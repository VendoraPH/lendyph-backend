<?php

namespace Database\Factories;

use App\Models\GCashNonMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GCashNonMember>
 */
class GCashNonMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'mobile_number' => '09'.fake()->numerify('#########'),
            'id_type' => fake()->randomElement(['UMID', 'Drivers License', 'Passport', 'PhilSys']),
            'id_number' => fake()->bothify('??########'),
            'remarks' => null,
        ];
    }
}
