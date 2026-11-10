<?php

namespace Database\Factories;

use App\Models\CdaReport;
use App\Models\CdaReportValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdaReportValue>
 */
class CdaReportValueFactory extends Factory
{
    protected $model = CdaReportValue::class;

    public function definition(): array
    {
        return [
            'report_id' => CdaReport::factory(),
            'field_key' => 'general.field_'.$this->faker->unique()->numberBetween(1, 999_999),
            'value' => 0,
        ];
    }
}
