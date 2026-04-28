<?php

namespace Database\Factories;

use App\Models\CollateralType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollateralType>
 */
class CollateralTypeFactory extends Factory
{
    protected $model = CollateralType::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'detail_field_label' => 'Reference No.',
            'amount_field_label' => 'Appraised Value',
            'source' => 'manual',
            'display_order' => 0,
            'is_visible' => true,
            'is_seed' => false,
        ];
    }

    public function seed(): static
    {
        return $this->state(['is_seed' => true]);
    }

    public function shareCapital(): static
    {
        return $this->state([
            'name' => 'Share Capital',
            'source' => 'share_capital',
            'detail_field_label' => 'Pledge Reference',
            'amount_field_label' => 'Amount',
        ]);
    }
}
