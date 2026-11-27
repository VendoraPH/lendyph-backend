<?php

namespace Tests\Feature\Portal\Requests;

use App\Models\PortalRequest;
use App\Models\PortalUser;
use App\Models\SavingsAccount;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;

/**
 * Shared set-up for the portal requests tests (slice P3): the savings set-up
 * (a cooperative chart, a regular product, "today" pinned to Nov 10, 2026 in
 * Manila, member Maria Santos in the seeded branch) plus the portal switched
 * on and Maria's active portal account, signed in.
 */
trait PortalRequestsTestHelpers
{
    use PortalTestHelpers, SavingsAccountsTestHelpers;

    protected PortalUser $portalUser;

    protected string $token;

    protected function setUpPortalRequests(): void
    {
        $this->setUpSavings();
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->member->forceFill([
            'birthdate' => '1990-05-17',
            'membership_date' => '2024-01-15',
            'civil_status' => 'single',
            'gender' => 'female',
            'contact_number' => '09171234567',
            'email' => 'maria.santos@example.com',
            'street_address' => '12 Mabini St',
            'address' => '12 Mabini St',
            'barangay' => 'San Roque',
            'city' => 'Manila',
            'province' => 'Metro Manila',
        ])->save();

        $this->portalUser = PortalUser::factory()->create(['borrower_id' => $this->member->id])->refresh();
        $this->token = $this->portalToken($this->portalUser);
    }

    /**
     * Forget the guards a previous request resolved, so each request
     * authenticates afresh, and put back the default guard a portal request
     * switched to `portal`, so `actingAs()` signs staff in again.
     */
    protected function freshRequest(): void
    {
        $this->app['auth']->setDefaultDriver('web');
        Auth::forgetGuards();
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
    }

    /** A member's portal request, with a fresh (or the given) Idempotency-Key. */
    protected function submit(array $body, ?string $key = null, ?string $token = null): TestResponse
    {
        $this->freshRequest();

        return $this->withToken($token ?? $this->token)
            ->postJson('/api/portal/requests', $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /** A signed-in member call. */
    protected function member(string $method, string $uri, array $body = [], ?string $token = null): TestResponse
    {
        $this->freshRequest();

        return $this->withToken($token ?? $this->token)->json($method, $uri, $body);
    }

    /** @return array{type: string, details: array<string, mixed>} */
    protected function withdrawalBody(SavingsAccount $account, string $amount, array $details = []): array
    {
        return ['type' => PortalRequest::TYPE_SAVINGS_WITHDRAWAL, 'details' => ['account_id' => $account->id, 'amount' => $amount, 'method' => 'cash_pickup', ...$details]];
    }

    /** A staff decision, as `$as` (the seeded admin by default), with a fresh (or the given) Idempotency-Key. */
    protected function decide(PortalRequest|int $request, array $body, ?User $as = null, ?string $key = null): TestResponse
    {
        $this->freshRequest();
        $this->actingAs($as ?? $this->admin);
        $id = $request instanceof PortalRequest ? $request->id : $request;

        return $this->postJson("/api/portal-admin/requests/{$id}/decision", $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /** @return array{date: string, method: string, reference: string|null} */
    protected function payout(array $overrides = []): array
    {
        return ['date' => '2026-11-10', 'method' => 'cash', 'reference' => null, ...$overrides];
    }

    /** A staff user holding the queue permission plus these, in the seeded branch. */
    protected function queueStaff(array $permissions = []): User
    {
        return $this->userWith(['member_portal:requests', ...$permissions]);
    }

    protected function submitted(TestResponse $response): PortalRequest
    {
        return PortalRequest::query()->findOrFail((int) $response->json('data.id'));
    }
}
