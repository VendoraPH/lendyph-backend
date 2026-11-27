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

    /** A walk-in registered without an ID document; the ID is optional. */
    public function withoutId(): static
    {
        return $this->state(fn () => [
            'id_type' => null,
            'id_number' => null,
        ]);
    }
}
