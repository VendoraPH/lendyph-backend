<?php

namespace Database\Factories;

use App\Models\GCashTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GCashTier>
 */
class GCashTierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'min_amount' => 1,
            'max_amount' => 1500,
            'cash_in_rate' => 20,
            'cash_out_rate' => 15,
            'display_order' => 1,
        ];
    }
}
