<?php

namespace Database\Factories;

use App\Models\PortalAnnouncement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A published announcement to every member.
 *
 * @extends Factory<PortalAnnouncement>
 */
class PortalAnnouncementFactory extends Factory
{
    protected $model = PortalAnnouncement::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'body' => $this->faker->paragraph(),
            'audience' => PortalAnnouncement::AUDIENCE_ALL,
            'status' => PortalAnnouncement::STATUS_PUBLISHED,
            'publish_at' => now()->subHour(),
        ];
    }
}
