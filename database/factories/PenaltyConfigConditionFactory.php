<?php

namespace Database\Factories;

use App\Models\PenaltyConfig;
use App\Models\PenaltyConfigCondition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PenaltyConfigCondition>
 */
class PenaltyConfigConditionFactory extends Factory
{
    protected $model = PenaltyConfigCondition::class;

    public function definition(): array
    {
        return [
            'penalty_config_id' => PenaltyConfig::factory(),
            'days_from' => 1,
            'days_to' => null,
            'rate' => 3,
        ];
    }
}
