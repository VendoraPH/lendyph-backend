<?php

namespace Tests\Feature\Portal;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\PortalSetting;
use App\Models\PortalTermsVersion;
use App\Models\PortalUser;
use App\Models\User;
use Database\Factories\PortalUserFactory;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

/**
 * Shared set-up for the Member Portal tests (every portal slice may use it).
 *
 * Requests go through `portalJson()`, which forgets the resolved guards
 * first: the auth manager caches the user a guard resolved, so a second
 * request in the same test would otherwise reuse the first one's user.
 */
trait PortalTestHelpers
{
    protected function enablePortal(array $settings = []): PortalSetting
    {
        if (PortalTermsVersion::current() === null) {
            $this->publishTerms();
        }

        $row = PortalSetting::query()->orderBy('id')->firstOrFail();
        $row->forceFill(['enabled' => true, ...$settings])->save();

        return $row->refresh();
    }

    protected function publishTerms(array $attributes = []): PortalTermsVersion
    {
        return PortalTermsVersion::factory()->create($attributes);
    }

    /**
     * An active member with an active portal account (password
     * PortalUserFactory::PASSWORD), in the given branch or a new one.
     */
    protected function portalMember(array $member = [], array $account = []): PortalUser
    {
        $borrower = Borrower::factory()->create([
            'branch_id' => $member['branch_id'] ?? Branch::factory()->create()->id,
            'birthdate' => '1990-05-17',
            'membership_date' => '2024-01-15',
            'status' => 'active',
            ...$member,
        ]);

        return PortalUser::factory()->create(['borrower_id' => $borrower->id, ...$account])->refresh();
    }

    protected function portalPassword(): string
    {
        return PortalUserFactory::PASSWORD;
    }

    protected function portalToken(PortalUser $portalUser, ?DateTimeInterface $expiresAt = null): string
    {
        return $portalUser->createToken('Test device', [PortalUser::ABILITY], $expiresAt ?? now()->addHour())->plainTextToken;
    }

    protected function staffToken(User $user): string
    {
        return $user->createToken('auth-token', ['*'], now()->addMinutes(30))->plainTextToken;
    }

    protected function memberNumber(PortalUser $portalUser): string
    {
        $member = $portalUser->borrower;

        return $member->membership_number ?: $member->borrower_code;
    }

    /** A request with this bearer token (or none), the guards forgotten first. */
    protected function portalJson(string $method, string $uri, array $data = [], ?string $token = null): TestResponse
    {
        Auth::forgetGuards();
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        return $token === null
            ? $this->json($method, $uri, $data)
            : $this->withToken($token)->json($method, $uri, $data);
    }
}
