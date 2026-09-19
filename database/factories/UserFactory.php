<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            // `alpha_dash` is the app's own rule on this column
            // (StoreUserRequest / UpdateUserRequest), and Faker's userName()
            // returns dotted forms like "shawna.sporer" for well over half its
            // output — 22 of 40 in a sample. So a factory user could not be
            // saved back through the API it models, and any spec that round-trips
            // a generated username was a coin flip on the faker sequence. One
            // such spec flipped the day two unrelated tests were added ahead of
            // it. Keep the generator inside the rule.
            'username' => str_replace('.', '_', fake()->unique()->userName()),
            'email' => fake()->unique()->safeEmail(),
            'mobile_number' => fake()->numerify('09#########'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'branch_id' => 1,
            'status' => 'active',
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
