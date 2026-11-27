<?php

namespace Database\Factories;

use App\Models\Borrower;
use App\Models\PortalUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active portal account with the password `Portal-pass-1`, unless a state
 * says otherwise.
 *
 * @extends Factory<PortalUser>
 */
class PortalUserFactory extends Factory
{
    protected $model = PortalUser::class;

    public const PASSWORD = 'Portal-pass-1';

    public function definition(): array
    {
        return [
            'borrower_id' => Borrower::factory(),
            'password' => self::PASSWORD,
            'status' => PortalUser::STATUS_ACTIVE,
            'invited_at' => now()->subDays(2),
            'activated_at' => now()->subDay(),
            'password_changed_at' => now()->subDay(),
            'terms_version' => 'test',
            'terms_accepted_at' => now(),
        ];
    }

    public function invited(): static
    {
        return $this->state(fn () => [
            'password' => null,
            'status' => PortalUser::STATUS_INVITED,
            'activated_at' => null,
            'password_changed_at' => null,
            'terms_version' => null,
            'terms_accepted_at' => null,
        ]);
    }

    public function disabled(string $reason = 'administrative'): static
    {
        return $this->state(fn () => [
            'status' => PortalUser::STATUS_DISABLED,
            'disabled_reason' => $reason,
            'disabled_at' => now(),
        ]);
    }

    public function locked(): static
    {
        return $this->state(fn () => ['status' => PortalUser::STATUS_LOCKED]);
    }
}
