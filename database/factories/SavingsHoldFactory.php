<?php

namespace Database\Factories;

use App\Models\SavingsAccount;
use App\Models\SavingsHold;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsHold>
 */
class SavingsHoldFactory extends Factory
{
    protected $model = SavingsHold::class;

    public function definition(): array
    {
        return [
            'account_id' => SavingsAccount::factory(),
            'type' => 'manual',
            'amount' => '100.00',
            'reason' => 'Held for verification',
            'placed_on' => now()->toDateString(),
            'expires_on' => null,
            'status' => 'active',
            'created_by' => null,
        ];
    }
}
