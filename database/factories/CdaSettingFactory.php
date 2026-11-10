<?php

namespace Database\Factories;

use App\Models\CdaSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdaSetting>
 */
class CdaSettingFactory extends Factory
{
    protected $model = CdaSetting::class;

    public function definition(): array
    {
        return CdaSetting::DEFAULTS;
    }
}
