<?php

namespace Tests\Feature\Portal\Auth;

use App\Models\Borrower;
use App\Models\PortalLoginEvent;
use App\Models\PortalUser;
use App\Models\User;
use App\Services\Portal\MemberPortalAccessEnded;
use App\Services\Portal\PortalStaffAlerts;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Portal sessions (portal design §1, §8): idle and absolute expiry, the
 * password change, terms re-acceptance, revocation on a member's status, and
 * the member's own sessions and sign-in history.
 */
class PortalSessionTest extends TestCase
{
    use PortalTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_an_idle_token_is_deleted_and_answers_401(): void
    {
        $portalUser = $this->portalMember();
        $token = $this->portalToken($portalUser);

        $this->portalJson('GET', '/api/portal/auth/me', [], $token)->assertOk();

        $this->travel(14)->minutes();
        $this->portalJson('GET', '/api/portal/auth/me', [], $token)->assertOk();

        $this->travel(16)->minutes();
        $this->portalJson('GET', '/api/portal/auth/me', [], $token)->assertStatus(401)->assertJsonPath('code', 'session_idle');
        $this->assertSame(0, $portalUser->tokens()->count());
    }

    public function test_a_token_past_its_absolute_expiry_is_deleted_and_answers_401(): void
    {
        $portalUser = $this->portalMember();
        $token = $this->portalToken($portalUser, now()->addMinutes(10));

        $this->travel(11)->minutes();

        $this->portalJson('GET', '/api/portal/auth/me', [], $token)->assertStatus(401);
        $this->assertSame(0, $portalUser->tokens()->count());
    }

    public function test_changing_the_password_keeps_this_session_and_ends_the_others(): void
    {
        $portalUser = $this->portalMember();
        $current = $this->portalToken($portalUser);
        $other = $this->portalToken($portalUser);

        $this->portalJson('POST', '/api/portal/auth/password/change', [
            'current_password' => 'Wrong-pass-1',
            'password' => 'Another-pass-77',
            'password_confirmation' => 'Another-pass-77',
        ], $current)->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->assertSame(1, $portalUser->fresh()->failed_attempts, 'A wrong current password counts toward the lockout.');

        $this->portalJson('POST', '/api/portal/auth/password/change', [
            'current_password' => $this->portalPassword(),
            'password' => 'Another-pass-77',
            'password_confirmation' => 'Another-pass-77',
        ], $current)->assertOk();

        $this->portalJson('GET', '/api/portal/auth/me', [], $current)->assertOk();
        $this->portalJson('GET', '/api/portal/auth/me', [], $other)->assertStatus(401);
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal_password_changed', 'actor_type' => 'member', 'portal_user_id' => $portalUser->id]);
        $this->assertSame(0, $portalUser->fresh()->failed_attempts);
    }

    public function test_five_wrong_current_passwords_lock_the_account_and_end_every_session(): void
    {
        $portalUser = $this->portalMember();
        $token = $this->portalToken($portalUser);

        for ($i = 0; $i < 5; $i++) {
            $this->portalJson('POST', '/api/portal/auth/password/change', [
                'current_password' => 'Wrong-pass-'.$i,
                'password' => 'Another-pass-77',
                'password_confirmation' => 'Another-pass-77',
            ], $token);
        }

        $this->assertTrue($portalUser->fresh()->isTemporarilyLocked());
        $this->portalJson('GET', '/api/portal/auth/me', [], $token)->assertStatus(401);
    }

    public function test_new_terms_that_need_accepting_hold_every_route_but_me_terms_and_logout(): void
    {
        $portalUser = $this->portalMember();
        $token = $this->portalToken($portalUser);
        $this->travel(1)->minutes();
        $terms = $this->publishTerms(['version' => '2026-12', 'requires_reacceptance' => true, 'published_at' => now()]);

        $this->portalJson('GET', '/api/portal/security/sessions', [], $token)->assertStatus(403)->assertJsonPath('code', 'terms_required');
        $this->portalJson('GET', '/api/portal/auth/me', [], $token)->assertOk()->assertJsonPath('data.terms_required', true);

        $this->portalJson('POST', '/api/portal/auth/terms/accept', ['version' => 'old'], $token)->assertStatus(422);
        $this->portalJson('POST', '/api/portal/auth/terms/accept', ['version' => $terms->version], $token)->assertOk();

        $this->portalJson('GET', '/api/portal/security/sessions', [], $token)->assertOk();
        $this->assertSame('2026-12', $portalUser->fresh()->terms_version);

        // A later version that doesn't need accepting holds nothing.
        $this->travel(1)->minutes();
        $this->publishTerms(['version' => '2026-12a', 'published_at' => now()]);
        $this->portalJson('GET', '/api/portal/security/sessions', [], $token)->assertOk();
    }

    public function test_a_member_leaving_active_ends_their_sessions_at_once(): void
    {
        $portalUser = $this->portalMember();
        $token = $this->portalToken($portalUser);

        $portalUser->borrower->update(['status' => 'inactive']);

        $this->assertSame(0, $portalUser->tokens()->count());
        $this->portalJson('GET', '/api/portal/auth/me', [], $token)->assertStatus(401);
        $this->assertSame(PortalUser::STATUS_ACTIVE, $portalUser->fresh()->status, 'Inactive blocks sign-in while it lasts; the account stays.');

        $portalUser->borrower->update(['status' => 'active']);
        $this->portalJson('POST', '/api/portal/auth/login', ['member_number' => $this->memberNumber($portalUser), 'password' => $this->portalPassword()])->assertOk();
    }

    public function test_a_bulk_status_update_is_caught_on_the_next_request(): void
    {
        $portalUser = $this->portalMember();
        $token = $this->portalToken($portalUser);

        Borrower::query()->whereKey($portalUser->borrower_id)->update(['status' => 'blacklisted']);

        $this->portalJson('GET', '/api/portal/auth/me', [], $token)->assertStatus(403)->assertJsonPath('code', 'membership_inactive');
        $this->assertSame(0, $portalUser->tokens()->count());
    }

    public function test_a_withdrawn_or_deceased_member_loses_portal_access_as_a_system_change(): void
    {
        Event::fake([MemberPortalAccessEnded::class]);

        foreach (['withdrawn' => PortalUser::REASON_MEMBERSHIP_ENDED, 'deceased' => PortalUser::REASON_DECEASED] as $status => $reason) {
            $portalUser = $this->portalMember();
            $this->portalToken($portalUser);

            $portalUser->borrower->forceFill(['status' => $status, 'membership_ended_on' => now()->toDateString()])->save();

            $fresh = $portalUser->fresh();
            $this->assertSame(PortalUser::STATUS_DISABLED, $fresh->status);
            $this->assertSame($reason, $fresh->disabled_reason);
            $this->assertNull($fresh->disabled_by);
            $this->assertSame(0, $portalUser->tokens()->count());
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'portal_access_disabled', 'auditable_id' => $portalUser->id, 'user_id' => null, 'actor_type' => null,
            ]);
            Event::assertDispatched(MemberPortalAccessEnded::class, fn (MemberPortalAccessEnded $event) => $event->portalUser->is($portalUser) && $event->reason === $reason);
        }
    }

    public function test_the_member_lists_and_ends_their_own_sessions(): void
    {
        $portalUser = $this->portalMember();
        $current = $this->portalToken($portalUser);
        $other = $this->portalToken($portalUser);
        $otherId = (int) explode('|', $other)[0];
        $currentId = (int) explode('|', $current)[0];

        $sessions = $this->portalJson('GET', '/api/portal/security/sessions', [], $current)->assertOk()->json('data');
        $this->assertCount(2, $sessions);
        $this->assertSame(['id', 'device', 'ip_address', 'created_at', 'last_active_at', 'expires_at', 'current'], array_keys($sessions[0]));
        $this->assertSame([$currentId], array_column(array_filter($sessions, fn ($s) => $s['current']), 'id'));

        $this->portalJson('DELETE', "/api/portal/security/sessions/{$currentId}", [], $current)->assertStatus(422);
        $this->portalJson('DELETE', "/api/portal/security/sessions/{$otherId}", [], $current)->assertOk();
        $this->assertNull(PersonalAccessToken::find($otherId));

        $third = $this->portalToken($portalUser);
        $this->portalJson('POST', '/api/portal/security/sessions/logout-others', [], $current)->assertOk()->assertJsonPath('data.sessions_ended', 1);
        $this->portalJson('GET', '/api/portal/auth/me', [], $third)->assertStatus(401);
        $this->portalJson('POST', '/api/portal/auth/logout', [], $current)->assertOk();
        $this->assertSame(0, $portalUser->tokens()->count());
    }

    public function test_a_reported_sign_in_signs_out_other_devices_and_tells_branch_staff(): void
    {
        $portalUser = $this->portalMember(['branch_id' => $this->branch->id]);
        $current = $this->portalToken($portalUser);
        $other = $this->portalToken($portalUser);
        $event = PortalLoginEvent::factory()->create(['portal_user_id' => $portalUser->id, 'device' => 'Firefox on Windows']);

        $manager = User::factory()->inBranches($this->branch)->create();
        $manager->givePermissionTo('member_portal:manage');

        $logins = $this->portalJson('GET', '/api/portal/security/logins', [], $current)->assertOk()->json('data');
        $this->assertSame('Signed in', $logins[0]['outcome_label']);

        $this->portalJson('POST', "/api/portal/security/logins/{$event->id}/report", [], $current)
            ->assertOk()
            ->assertJsonPath('data.password_change_required', true)
            ->assertJsonPath('data.sessions_ended', 1);

        $this->assertNotNull($event->fresh()->reported_at);
        $this->portalJson('GET', '/api/portal/auth/me', [], $other)->assertStatus(401);
        $this->assertSame(1, $manager->notifications()->where('type', PortalStaffAlerts::LOGIN_REPORTED)->count());
        $this->assertSame(1, $portalUser->notifications()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal_login_reported', 'actor_type' => 'member']);
    }

    public function test_portal_answers_are_never_cached(): void
    {
        $portalUser = $this->portalMember();

        $this->portalJson('GET', '/api/portal/config')->assertHeader('Cache-Control', 'no-store, private');
        $this->portalJson('GET', '/api/portal/auth/me', [], $this->portalToken($portalUser))->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_access_turned_off_while_signed_in_ends_the_session_on_the_next_request(): void
    {
        $portalUser = $this->portalMember();
        $token = $this->portalToken($portalUser);

        PortalUser::query()->whereKey($portalUser->id)->update(['status' => PortalUser::STATUS_DISABLED]);

        $this->portalJson('GET', '/api/portal/auth/me', [], $token)->assertStatus(403)->assertJsonPath('code', 'portal_access_disabled');
        $this->assertSame(0, $portalUser->tokens()->count());

        $active = $this->portalMember();
        $activeToken = $this->portalToken($active);
        $this->enablePortal(['enabled' => false]);
        $this->portalJson('GET', '/api/portal/auth/me', [], $activeToken)->assertStatus(503)->assertJsonPath('code', 'portal_off');
    }
}
