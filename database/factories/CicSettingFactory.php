<?php

namespace Database\Factories;

use App\Models\CicSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CicSetting>
 */
class CicSettingFactory extends Factory
{
    protected $model = CicSetting::class;

    public function definition(): array
    {
        return [
            'provider_code' => strtoupper($this->faker->bothify('??##??##')),
            'format_version' => '1.0',
            'is_mfi' => false,
            'institution_name' => 'Test Cooperative',
            'contact_name' => 'Test Contact',
            'contact_email' => 'cic@example.test',
            'contact_phone' => '09170000000',
        ];
    }

    /** No provider code yet (a new deployment). */
    public function withoutProviderCode(): static
    {
        return $this->state(['provider_code' => null]);
    }
}
