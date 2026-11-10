<?php

namespace Database\Factories;

use App\Models\SavingsTaxRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsTaxRule>
 */
class SavingsTaxRuleFactory extends Factory
{
    protected $model = SavingsTaxRule::class;

    public function definition(): array
    {
        return [
            'holder_type' => SavingsTaxRule::HOLDER_MEMBER,
            'rate' => '0.0000',
            'effective_from' => now()->toDateString(),
            'created_by' => null,
        ];
    }
}
