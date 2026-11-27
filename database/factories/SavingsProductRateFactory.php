<?php

namespace Database\Factories;

use App\Models\SavingsProduct;
use App\Models\SavingsProductRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsProductRate>
 */
class SavingsProductRateFactory extends Factory
{
    protected $model = SavingsProductRate::class;

    public function definition(): array
    {
        return [
            'product_id' => SavingsProduct::factory(),
            'term_days' => null,
            'annual_rate' => '1.0000',
            'effective_from' => '2026-01-01',
            'created_by' => null,
        ];
    }
}
