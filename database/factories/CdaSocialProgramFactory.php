<?php

namespace Database\Factories;

use App\Models\CdaReportingPeriod;
use App\Models\CdaSocialProgram;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdaSocialProgram>
 */
class CdaSocialProgramFactory extends Factory
{
    protected $model = CdaSocialProgram::class;

    public function definition(): array
    {
        return [
            'period_id' => CdaReportingPeriod::factory(),
            'name' => $this->faker->sentence(3),
            'category' => 'community',
            'actual_expense' => '0.00',
            'cdf_amount' => '0.00',
            'status' => 'completed',
        ];
    }
}
