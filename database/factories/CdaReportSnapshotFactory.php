<?php

namespace Database\Factories;

use App\Models\CdaReport;
use App\Models\CdaReportSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdaReportSnapshot>
 */
class CdaReportSnapshotFactory extends Factory
{
    protected $model = CdaReportSnapshot::class;

    public function definition(): array
    {
        return [
            'report_id' => CdaReport::factory(),
            'version' => 1,
            'payload' => '{}',
            'checksum' => hash('sha256', '{}'),
            'finalized_at' => now(),
        ];
    }
}
