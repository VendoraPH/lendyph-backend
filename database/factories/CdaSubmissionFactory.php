<?php

namespace Database\Factories;

use App\Models\CdaReport;
use App\Models\CdaSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdaSubmission>
 */
class CdaSubmissionFactory extends Factory
{
    protected $model = CdaSubmission::class;

    public function definition(): array
    {
        return [
            'report_id' => CdaReport::factory(),
            'method' => 'cais',
            'submitted_on' => now()->toDateString(),
            'status' => 'submitted',
        ];
    }
}
