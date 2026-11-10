<?php

namespace Database\Factories;

use App\Models\SavingsSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsSetting>
 */
class SavingsSettingFactory extends Factory
{
    protected $model = SavingsSetting::class;

    public function definition(): array
    {
        return SavingsSetting::DEFAULTS;
    }
}
