<?php

namespace Database\Factories;

use App\Models\PortalRequest;
use App\Models\PortalUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A submitted profile change request for the portal user's member, in the
 * member's branch.
 *
 * @extends Factory<PortalRequest>
 */
class PortalRequestFactory extends Factory
{
    protected $model = PortalRequest::class;

    public function definition(): array
    {
        return [
            'reference' => 'REQ-'.now()->format('Ymd').'-'.$this->faker->unique()->numerify('######'),
            'portal_user_id' => PortalUser::factory(),
            'borrower_id' => fn (array $attributes) => PortalUser::query()->findOrFail($attributes['portal_user_id'])->borrower_id,
            'branch_id' => fn (array $attributes) => PortalUser::query()->findOrFail($attributes['portal_user_id'])->borrower->branch_id,
            'type' => PortalRequest::TYPE_PROFILE_UPDATE,
            'status' => PortalRequest::STATUS_SUBMITTED,
            'details' => ['city' => 'Quezon City'],
            'current_values' => ['city' => 'Manila'],
        ];
    }
}
