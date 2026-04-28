<?php

namespace Database\Factories;

use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\CollateralType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Collateral>
 */
class CollateralFactory extends Factory
{
    protected $model = Collateral::class;

    public function definition(): array
    {
        return [
            'borrower_id' => Borrower::factory(),
            'collateral_type_id' => CollateralType::factory(),
            'detail_value' => 'TCT-'.$this->faker->numerify('######'),
            'amount' => $this->faker->randomFloat(2, 1000, 500000),
        ];
    }
}
