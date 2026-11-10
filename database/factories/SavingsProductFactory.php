<?php

namespace Database\Factories;

use App\Models\SavingsProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active regular product by default (the factory skips the books check
 * the API makes on activation). `timeDeposit()` for a time deposit product.
 *
 * @extends Factory<SavingsProduct>
 */
class SavingsProductFactory extends Factory
{
    protected $model = SavingsProduct::class;

    public function definition(): array
    {
        return [
            'code' => 'SV-'.$this->faker->unique()->numerify('#####'),
            'name' => 'Regular Savings '.$this->faker->unique()->numerify('###'),
            'kind' => SavingsProduct::KIND_REGULAR,
            'interest_crediting' => 'quarterly',
            'minimum_opening_balance' => '0.00',
            'maintaining_balance' => '0.00',
            'minimum_adb_for_interest' => '0.00',
            'term_options_days' => null,
            'pretermination_product_id' => null,
            'is_active' => true,
        ];
    }

    public function timeDeposit(): static
    {
        return $this->state(fn () => [
            'code' => 'TD-'.$this->faker->unique()->numerify('#####'),
            'name' => 'Time Deposit '.$this->faker->unique()->numerify('###'),
            'kind' => SavingsProduct::KIND_TIME_DEPOSIT,
            'interest_crediting' => 'annual',
            'term_options_days' => [90, 180, 360],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
