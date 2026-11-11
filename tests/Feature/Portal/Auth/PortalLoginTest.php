<?php

namespace Tests\Feature\Portal\Auth;

use App\Models\AuditLog;
use App\Models\PortalLoginEvent;
use App\Models\PortalUser;
use App\Services\Portal\Auth\MemberNumber;
use App\Services\Portal\Auth\PortalLogin;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;

/**
 * Member sign-in (portal design §1, §8): generic refusals, lockout, the
 * disabled and inactive answers, the portal switch and the limits keyed on
 * the member number.
 */
class PortalLoginTest extends TestCase
{
    use PortalTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePortal();
    }

    private function login(string $memberNumber, string $password): TestResponse
    {
        return $this->portalJson('POST', '/api/portal/auth/login', ['member_number' => $memberNumber, 'password' => $password]);
    }

    public function test_a_member_signs_in_with_member_number_and_password(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $portalUser = $this->portalMember(['membership_number' => 'M-2024-0007']);

        $response = $this->login(' m-2024- 0007 ', $this->portalPassword())->assertOk();

        $response->assertJsonStructure(['success', 'data' => ['token', 'expires_in', 'idle_minutes', 'member' => ['first_name', 'member_number'], 'terms_required']]);
        $this->assertSame('M-2024-0007', $response->json('data.member.member_number'));
        $this->assertFalse($response->json('data.terms_required'));
        $this->assertEqualsWithDelta(3600, $response->json('data.expires_in'), 5);
        $this->assertSame(15, $response->json('data.idle_minutes'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));

        $this->portalJson('GET', '/api/portal/auth/me', [], $response->json('data.token'))
            ->assertOk()
            ->assertJsonPath('data.member.member_number', 'M-2024-0007');

        $event = PortalLoginEvent::query()->where('portal_user_id', $portalUser->id)->latest('id')->firstOrFail();
        $this->assertSame('success', $event->outcome);
        $this->assertNotNull($event->token_id);
        $this->assertNotSame('M-2024-0007', $event->login_hash);

        $audit = AuditLog::query()->where('action', 'portal_signed_in')->latest('id')->firstOrFail();
        $this->assertNull($audit->user_id);
        $this->assertSame('member', $audit->actor_type);
        $this->assertSame($portalUser->id, (int) $audit->portal_user_id);

        $this->assertSame(1, $portalUser->notifications()->count(), 'A new sign-in notice is written.');
        $this->assertNotNull($portalUser->fresh()->last_login_at);
    }

    public function test_the_member_code_signs_in_when_there_is_no_membership_number(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $portalUser = $this->portalMember(['membership_date' => null]);
        $portalUser->borrower->forceFill(['membership_number' => null])->save();

        $this->login(strtolower($portalUser->borrower->borrower_code), $this->portalPassword())->assertOk();
    }

    public function test_refusals_are_byte_identical_whether_or_not_the_account_exists(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $active = $this->portalMember();
        $invited = $this->portalMember([], ['password' => null, 'status' => PortalUser::STATUS_INVITED]);
        $locked = $this->portalMember([], ['locked_until' => now()->addMinutes(10)]);
        $hardLocked = $this->portalMember([], ['status' => PortalUser::STATUS_LOCKED]);

        $bodies = [
            'unknown number' => $this->login('NOBODY-123', $this->portalPassword())->assertStatus(401)->getContent(),
            'wrong password' => $this->login($this->memberNumber($active), 'Wrong-pass-9')->assertStatus(401)->getContent(),
            'not activated' => $this->login($this->memberNumber($invited), $this->portalPassword())->assertStatus(401)->getContent(),
            'locked, right password' => $this->login($this->memberNumber($locked), $this->portalPassword())->assertStatus(401)->getContent(),
            'locked until staff, right password' => $this->login($this->memberNumber($hardLocked), $this->portalPassword())->assertStatus(401)->getContent(),
        ];

        $this->assertCount(1, array_unique($bodies), 'Every refusal body is the same: '.json_encode($bodies));
        $this->assertSame(['success' => false, 'message' => PortalLogin::FAILED], json_decode($bodies['unknown number'], true));
        $this->assertDatabaseHas('portal_login_events', ['portal_user_id' => null, 'login_hash' => MemberNumber::hash('NOBODY-123'), 'outcome' => 'failed']);
        $this->assertDatabaseHas('portal_login_events', ['portal_user_id' => $locked->id, 'outcome' => 'refused_locked']);
    }

    public function test_five_wrong_passwords_lock_the_account_and_the_password_is_not_checked_while_locked(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $portalUser = $this->portalMember();
        $number = $this->memberNumber($portalUser);

        for ($i = 0; $i < 4; $i++) {
            $this->login($number, 'Wrong-pass-'.$i)->assertStatus(401);
        }
        $this->assertSame(4, $portalUser->fresh()->failed_attempts);
        $this->assertNull($portalUser->fresh()->locked_until);

        $this->login($number, 'Wrong-pass-5')->assertStatus(401);
        $locked = $portalUser->fresh();
        $this->assertTrue($locked->isTemporarilyLocked());
        $this->assertEqualsWithDelta(15, now()->diffInMinutes($locked->locked_until), 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal_locked_out', 'user_id' => null, 'actor_type' => null]);

        $this->login($number, $this->portalPassword())->assertStatus(401)->assertExactJson(['success' => false, 'message' => PortalLogin::FAILED]);

        $this->travel(16)->minutes();
        $this->login($number, $this->portalPassword())->assertOk();
        $this->assertSame(0, $portalUser->fresh()->failed_attempts);
    }

    public function test_three_lockouts_in_a_day_lock_the_account_until_staff_unlock_it(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $portalUser = $this->portalMember();
        $number = $this->memberNumber($portalUser);
        $session = $this->portalToken($portalUser);

        for ($lockout = 0; $lockout < 3; $lockout++) {
            for ($i = 0; $i < 5; $i++) {
                $this->login($number, 'Wrong-pass-'.$i)->assertStatus(401);
            }
            $this->travel(16)->minutes();
        }

        $this->assertSame(PortalUser::STATUS_LOCKED, $portalUser->fresh()->status);
        $this->assertSame(0, $portalUser->tokens()->count(), 'A hard lock ends every session.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal_hard_locked', 'user_id' => null]);
        $this->login($number, $this->portalPassword())->assertStatus(401);
        $this->portalJson('GET', '/api/portal/auth/me', [], $session)->assertStatus(401);
    }

    public function test_a_disabled_account_answers_403_in_words_only_after_the_right_password(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $portalUser = $this->portalMember([], ['status' => PortalUser::STATUS_DISABLED, 'disabled_reason' => 'administrative']);

        $this->login($this->memberNumber($portalUser), 'Wrong-pass-1')->assertStatus(401)->assertExactJson(['success' => false, 'message' => PortalLogin::FAILED]);
        $this->login($this->memberNumber($portalUser), $this->portalPassword())
            ->assertStatus(403)
            ->assertJsonPath('message', PortalLogin::DISABLED)
            ->assertJsonPath('code', 'portal_access_disabled');
        $this->assertSame(0, $portalUser->fresh()->failed_attempts, 'A disabled account counts no failures.');
    }

    public function test_an_inactive_member_answers_403_in_words_only_after_the_right_password(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (['inactive', 'blacklisted'] as $status) {
            $portalUser = $this->portalMember(['status' => $status]);

            $this->login($this->memberNumber($portalUser), 'Wrong-pass-1')->assertStatus(401);
            $this->login($this->memberNumber($portalUser), $this->portalPassword())
                ->assertStatus(403)
                ->assertJsonPath('code', 'membership_inactive');
        }
    }

    public function test_sign_in_answers_503_while_the_portal_is_off_and_records_the_attempt(): void
    {
        $portalUser = $this->portalMember();
        $this->enablePortal(['enabled' => false]);

        $this->login($this->memberNumber($portalUser), $this->portalPassword())
            ->assertStatus(503)
            ->assertJsonPath('code', 'portal_off');
        $this->assertDatabaseHas('portal_login_events', ['portal_user_id' => $portalUser->id, 'outcome' => 'portal_off']);
        $this->assertSame(0, $portalUser->tokens()->count());

        $this->portalJson('GET', '/api/portal/config')->assertOk()->assertJsonPath('data.enabled', false);
        $this->portalJson('GET', '/api/portal/terms')->assertStatus(503);
    }

    /**
     * §0, finding 8: every member reaches the API from the proxy's address,
     * so the limit counts per member number, never per IP.
     */
    public function test_the_sign_in_limit_counts_per_member_number_not_per_address(): void
    {
        $first = $this->portalMember();
        $second = $this->portalMember();

        for ($i = 0; $i < 10; $i++) {
            $this->login($this->memberNumber($first), 'Wrong-pass-'.$i)->assertStatus(401);
            PortalUser::query()->whereKey($first->id)->update(['locked_until' => null, 'failed_attempts' => 0]);
        }

        $this->login($this->memberNumber($first), $this->portalPassword())->assertStatus(429);
        $this->login(' '.strtolower($this->memberNumber($first)), $this->portalPassword())->assertStatus(429);
        $this->login($this->memberNumber($second), $this->portalPassword())->assertOk();
    }
}
