<?php

namespace Database\Factories;

use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdaReport>
 */
class CdaReportFactory extends Factory
{
    protected $model = CdaReport::class;

    public function definition(): array
    {
        return [
            'period_id' => CdaReportingPeriod::factory(),
            'report_type' => 'capr',
            'part' => 'annual',
            'status' => CdaReport::STATUS_DRAFT,
            'applicable' => true,
            'default_due_on' => fn (array $attributes) => CdaReportingPeriod::query()->whereKey($attributes['period_id'])->value('default_deadline'),
        ];
    }
}
