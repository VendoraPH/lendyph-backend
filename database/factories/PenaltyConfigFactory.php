<?php

namespace Database\Factories;

use App\Models\LoanProduct;
use App\Models\PenaltyConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Writes straight to the tables, as a seeded configuration would, without the
 * service's clash check or audit rows. Tests of those go through the API.
 *
 * @extends Factory<PenaltyConfig>
 */
class PenaltyConfigFactory extends Factory
{
    protected $model = PenaltyConfig::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true).' Penalty',
            'status' => PenaltyConfig::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => PenaltyConfig::STATUS_INACTIVE]);
    }

    /**
     * @param  list<array{days_from: int, days_to: int|null, rate: int|float|string}>  $brackets
     */
    public function withBrackets(array $brackets): static
    {
        return $this->afterCreating(function (PenaltyConfig $config) use ($brackets): void {
            foreach ($brackets as $bracket) {
                $config->conditions()->create($bracket);
            }
        });
    }

    public function forProducts(LoanProduct ...$products): static
    {
        return $this->afterCreating(function (PenaltyConfig $config) use ($products): void {
            $config->products()->attach(array_map(fn (LoanProduct $product): int => $product->id, $products));
        });
    }
}
