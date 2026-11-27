<?php

namespace Database\Factories;

use App\Models\CdaComplianceRequirement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdaComplianceRequirement>
 */
class CdaComplianceRequirementFactory extends Factory
{
    protected $model = CdaComplianceRequirement::class;

    public function definition(): array
    {
        return [
            'year' => 2026,
            'title' => 'Mayor\'s permit renewal',
            'default_due_on' => '2027-01-20',
            'status' => CdaComplianceRequirement::STATUS_PENDING,
        ];
    }
}
