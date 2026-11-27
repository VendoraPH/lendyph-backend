<?php

namespace Database\Factories;

use App\Models\SavingsIdempotencyKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SavingsIdempotencyKey>
 */
class SavingsIdempotencyKeyFactory extends Factory
{
    protected $model = SavingsIdempotencyKey::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', 'POST api/savings/accounts {}'),
            'response_status' => 201,
            'response_body' => ['success' => true],
        ];
    }
}
