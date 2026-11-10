<?php

namespace Database\Factories;

use App\Models\CdaMediationCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdaMediationCase>
 */
class CdaMediationCaseFactory extends Factory
{
    protected $model = CdaMediationCase::class;

    public function definition(): array
    {
        return [
            'filed_on' => '2026-03-01',
            'status' => 'pending',
            'meetings_count' => 0,
        ];
    }
}
