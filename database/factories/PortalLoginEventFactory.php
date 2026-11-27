<?php

namespace Database\Factories;

use App\Models\PortalLoginEvent;
use App\Models\PortalUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortalLoginEvent>
 */
class PortalLoginEventFactory extends Factory
{
    protected $model = PortalLoginEvent::class;

    public function definition(): array
    {
        return [
            'portal_user_id' => PortalUser::factory(),
            'login_hash' => hash('sha256', $this->faker->uuid()),
            'outcome' => PortalLoginEvent::OUTCOME_SUCCESS,
            'device' => 'Chrome on Android',
            'user_agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0',
            'ip_address' => '10.0.0.1',
        ];
    }
}
