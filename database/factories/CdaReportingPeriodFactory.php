<?php

namespace Database\Factories;

use App\Models\CdaReportingPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdaReportingPeriod>
 */
class CdaReportingPeriodFactory extends Factory
{
    protected $model = CdaReportingPeriod::class;

    public function definition(): array
    {
        return [
            'year' => $year = $this->faker->unique()->numberBetween(2100, 2999),
            'period_start' => "{$year}-01-01",
            'period_end' => "{$year}-12-31",
            'default_deadline' => ($year + 1).'-04-30',
        ];
    }
}
