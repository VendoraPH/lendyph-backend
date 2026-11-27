<?php

namespace Database\Factories;

use App\Models\CdaRegulatoryProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdaRegulatoryProfile>
 */
class CdaRegulatoryProfileFactory extends Factory
{
    protected $model = CdaRegulatoryProfile::class;

    public function definition(): array
    {
        return [
            'cte_status' => 'none',
            'asset_class_source' => 'computed',
        ];
    }
}
