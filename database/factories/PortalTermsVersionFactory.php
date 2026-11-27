<?php

namespace Database\Factories;

use App\Models\PortalTermsVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A published terms version.
 *
 * @extends Factory<PortalTermsVersion>
 */
class PortalTermsVersionFactory extends Factory
{
    protected $model = PortalTermsVersion::class;

    public function definition(): array
    {
        return [
            'version' => 'v'.$this->faker->unique()->numerify('####'),
            'title' => 'Terms of Use (Draft)',
            'terms_body' => 'Draft terms of use.',
            'privacy_body' => 'Draft privacy notice.',
            'consent_body' => 'Draft electronic communication consent.',
            'requires_reacceptance' => false,
            'is_draft' => false,
            'published_at' => now()->subMinute(),
        ];
    }
}
