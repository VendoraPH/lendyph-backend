<?php

namespace Database\Factories;

use App\Models\CdaOfficer;
use App\Models\CdaOfficerTraining;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdaOfficerTraining>
 */
class CdaOfficerTrainingFactory extends Factory
{
    protected $model = CdaOfficerTraining::class;

    public function definition(): array
    {
        return [
            'officer_id' => CdaOfficer::factory(),
            'title' => 'Fundamentals of Cooperatives',
            'hours' => '8.00',
            'status' => 'completed',
            'completed_on' => '2026-03-01',
        ];
    }
}
