<?php

namespace Tests\Feature\Portal\Auth;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\PortalCode;
use App\Models\PortalTermsVersion;
use App\Models\PortalUser;
use App\Services\Portal\Auth\AccessCode;
use App\Services\Portal\Auth\CodeDesk;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Staff-issued activation and reset codes (portal design §1, §8).
 */
class PortalCodesTest extends TestCase
{
    use PortalTestHelpers, SetupLendyPH;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    private function newMember(array $attributes = []): Borrower
    {
        return Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'birthdate' => '1988-02-29',
            'membership_date' => '2024-01-15',
            'status' => 'active',
            ...$attributes,
        ]);
    }

    private function invite(Borrower $member, string $route = 'invite'): TestResponse
    {
        $this->actingAs($this->admin);

        return $this->postJson("/api/portal-admin/members/{$member->id}/{$route}", ['identity_checked_as' => 'UMID']);
    }

    private function numberOf(Borrower $member): string
    {
        return $member->fresh()->membership_number ?: $member->borrower_code;
    }

    private function verify(Borrower $member, string $code, string $birthdate = '1988-02-29'): TestResponse
    {
        return $this->portalJson('POST', '/api/portal/auth/codes/verify', [
            'member_number' => $this->numberOf($member),
            'code' => $code,
            'birthdate' => $birthdate,
        ]);
    }

    private function complete(string $step, array $extra = []): TestResponse
    {
        return $this->portalJson('POST', '/api/portal/auth/codes/complete', [
            'step_token' => $step,
            'password' => 'Brand-new-pass-42',
            'password_confirmation' => 'Brand-new-pass-42',
            'accepted_terms_version' => PortalTermsVersion::current()->version,
            ...$extra,
        ]);
    }

    public function test_staff_issue_an_activation_code_once_and_only_its_hash_is_kept(): void
    {
        $member = $this->newMember();

        $response = $this->invite($member)->assertCreated();
        $code = (string) $response->json('data.code');

        $this->assertMatchesRegularExpression('/^['.AccessCode::ALPHABET.']{4}-['.AccessCode::ALPHABET.']{4}-['.AccessCode::ALPHABET.']{4}$/', $code);
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('activation', $response->json('data.kind'));
        $this->assertSame($code, $response->json('data.slip.code'));
        $this->assertSame($this->numberOf($member), $response->json('data.slip.member_number'));
        $this->assertCount(3, $response->json('data.slip.instructions'));
        $this->assertStringContainsString('(Philippine time)', $response->json('data.slip.expires_at_text'));

        $plain = str_replace('-', '', $code);
        $stored = PortalCode::query()->where('portal_user_id', PortalUser::where('borrower_id', $member->id)->value('id'))->firstOrFail();
        $this->assertNotSame($plain, $stored->code_hash);
        $this->assertEqualsWithDelta(72, now()->diffInHours($stored->expires_at), 1);
        $this->assertSame('UMID', $stored->identity_checked_as);

        // Nowhere in the database or the logs.
        foreach (DB::select('show tables') as $table) {
            $name = array_values((array) $table)[0];
            $dump = json_encode(DB::table($name)->get());
            $this->assertStringNotContainsString($plain, (string) $dump, "The code is stored in {$name}.");
            $this->assertStringNotContainsString($code, (string) $dump, "The code is stored in {$name}.");
        }
        $this->assertStringNotContainsString($plain, implode("\n", $this->logged));

        $audit = AuditLog::query()->where('action', 'portal_code_issued')->latest('id')->firstOrFail();
        $this->assertSame($this->admin->id, (int) $audit->user_id);
        $this->assertSame(['kind', 'expires_at', 'identity_checked_as'], array_keys($audit->new_values));
        $this->assertStringContainsString('valid ID checked: UMID', (string) $audit->description);

        // The access card never shows it.
        $this->actingAs($this->admin)->getJson("/api/portal-admin/members/{$member->id}/access")
            ->assertOk()
            ->assertJsonPath('data.state', 'invited')
            ->assertJsonPath('data.code.kind', 'activation')
            ->assertJsonPath('data.code.status', 'open')
            ->assertDontSee($code);
    }

    public function test_redeeming_needs_the_birthdate_and_sets_the_password_and_signs_in(): void
    {
        $member = $this->newMember();
        $code = (string) $this->invite($member)->json('data.code');

        $this->verify($member, $code, '1988-03-01')->assertStatus(422);

        $verified = $this->verify($member, strtolower(str_replace('-', ' ', $code)))->assertOk();
        $verified->assertJsonPath('data.kind', 'activation')
            ->assertJsonPath('data.first_name', $member->first_name)
            ->assertJsonPath('data.otp_required', false)
            ->assertJsonPath('data.terms.version', PortalTermsVersion::current()->version);

        $step = (string) $verified->json('data.step_token');

        $this->complete($step, ['accepted_terms_version' => 'old'])->assertStatus(422)->assertJsonValidationErrors('accepted_terms_version');
        $this->complete($step, ['password' => 'onlyletters-here', 'password_confirmation' => 'onlyletters-here'])->assertStatus(422)->assertJsonValidationErrors('password');
        $number = $this->numberOf($member);
        $this->complete($step, ['password' => 'x1'.$number.'abc', 'password_confirmation' => 'x1'.$number.'abc'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->complete($step, ['password' => 'Short-1', 'password_confirmation' => 'Short-1'])->assertStatus(422)->assertJsonValidationErrors('password');

        $done = $this->complete($step, ['ecomm_consent' => true])->assertOk();
        $this->assertNotEmpty($done->json('data.token'));

        $portalUser = PortalUser::where('borrower_id', $member->id)->firstOrFail();
        $this->assertSame(PortalUser::STATUS_ACTIVE, $portalUser->status);
        $this->assertNotNull($portalUser->activated_at);
        $this->assertNotNull($portalUser->ecomm_consent_at);
        $this->assertSame(PortalTermsVersion::current()->version, $portalUser->terms_version);
        $this->assertSame(PortalCode::STATUS_USED, PortalCode::where('portal_user_id', $portalUser->id)->value('status'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal_activated', 'actor_type' => 'member', 'portal_user_id' => $portalUser->id, 'user_id' => null]);

        // The code and the step are spent.
        $this->complete($step)->assertStatus(422);
        $this->verify($member, $code)->assertStatus(422);

        $this->portalJson('POST', '/api/portal/auth/login', ['member_number' => $number, 'password' => 'Brand-new-pass-42'])->assertOk();
    }

    public function test_the_code_refusal_is_byte_identical_for_an_unknown_and_a_known_member(): void
    {
        $member = $this->newMember();
        $code = (string) $this->invite($member)->json('data.code');

        $unknown = $this->portalJson('POST', '/api/portal/auth/codes/verify', ['member_number' => 'NOBODY-1', 'code' => $code, 'birthdate' => '1988-02-29'])->assertStatus(422)->getContent();
        $wrongCode = $this->verify($member, 'AAAA-BBBB-CCCC')->assertStatus(422)->getContent();
        $wrongBirthdate = $this->verify($member, $code, '1970-01-01')->assertStatus(422)->getContent();
        $notInvited = $this->verify($this->newMember(), $code)->assertStatus(422)->getContent();

        $this->assertSame($unknown, $wrongCode);
        $this->assertSame($unknown, $wrongBirthdate);
        $this->assertSame($unknown, $notInvited);
        $this->assertSame(CodeDesk::CODE_FAILED, json_decode($unknown, true)['message']);
    }

    public function test_five_wrong_tries_burn_the_code(): void
    {
        $member = $this->newMember();
        $code = (string) $this->invite($member)->json('data.code');

        for ($i = 0; $i < 5; $i++) {
            $this->verify($member, $code, '1970-01-0'.($i + 1))->assertStatus(422);
        }

        $this->assertSame(PortalCode::STATUS_BURNED, PortalCode::query()->latest('id')->value('status'));
        $this->verify($member, $code)->assertStatus(422);

        $this->actingAs($this->admin)->getJson("/api/portal-admin/members/{$member->id}/access")->assertJsonPath('data.code.status', 'burned');
    }

    public function test_an_expired_code_is_refused(): void
    {
        $member = $this->newMember();
        $code = (string) $this->invite($member)->json('data.code');

        $this->travel(73)->hours();

        $this->verify($member, $code)->assertStatus(422)->assertJsonPath('message', CodeDesk::CODE_FAILED);
        $this->assertSame(PortalCode::STATUS_EXPIRED, PortalCode::query()->latest('id')->value('status'));
    }

    public function test_issuing_again_supersedes_the_open_code(): void
    {
        $member = $this->newMember();
        $first = (string) $this->invite($member)->json('data.code');
        $second = (string) $this->invite($member)->assertCreated()->json('data.code');

        $this->verify($member, $first)->assertStatus(422);
        $this->verify($member, $second)->assertOk();
        $this->assertSame(1, PortalCode::where('status', PortalCode::STATUS_OPEN)->count());
        $this->assertSame(PortalCode::STATUS_SUPERSEDED, PortalCode::query()->orderBy('id')->value('status'));
    }

    public function test_at_most_five_codes_per_member_a_day_and_thirty_per_staff_user_an_hour(): void
    {
        $member = $this->newMember();

        for ($i = 0; $i < 5; $i++) {
            $this->invite($member)->assertCreated();
        }
        $this->invite($member)->assertStatus(429);

        $this->travel(25)->hours();
        $this->invite($member)->assertCreated();

        $portalUser = PortalUser::factory()->invited()->create();
        PortalCode::query()->insert(array_fill(0, 30, [
            'portal_user_id' => $portalUser->id,
            'kind' => 'activation',
            'code_hash' => 'x',
            'expires_at' => now()->addDay(),
            'status' => PortalCode::STATUS_SUPERSEDED,
            'issued_by' => $this->admin->id,
            'created_at' => now(),
        ]));

        $this->invite($this->newMember())->assertStatus(429)->assertJsonPath('message', 'You issued 30 codes in the last hour. Try again later.');
    }

    public function test_an_invite_is_refused_in_words_when_it_cannot_work(): void
    {
        $this->invite($this->newMember(['status' => 'inactive']))->assertStatus(422);
        $this->invite($this->newMember(['birthdate' => null]))->assertStatus(422);
        $this->actingAs($this->admin)->postJson('/api/portal-admin/members/'.$this->newMember()->id.'/invite', [])
            ->assertStatus(422)->assertJsonValidationErrors('identity_checked_as');

        $active = $this->portalMember(['branch_id' => $this->branch->id]);
        $this->invite($active->borrower)->assertStatus(409);

        $invitedOnly = $this->newMember();
        $this->invite($invitedOnly);
        $this->invite($invitedOnly, 'reset-code')->assertStatus(422);

        PortalTermsVersion::query()->update(['is_draft' => true]);
        $this->invite($this->newMember())->assertStatus(422)->assertJsonPath('message', 'Publish the portal terms first (Settings → Member Portal).');
    }

    public function test_a_reset_code_clears_the_lock_signs_out_every_device_and_signs_in(): void
    {
        $portalUser = $this->portalMember(['branch_id' => $this->branch->id, 'birthdate' => '1988-02-29'], ['status' => PortalUser::STATUS_LOCKED, 'failed_attempts' => 3]);
        $oldSession = $this->portalToken($portalUser);
        $member = $portalUser->borrower;

        $response = $this->invite($member, 'reset-code')->assertCreated();
        $this->assertSame('reset', $response->json('data.kind'));
        $this->assertEqualsWithDelta(24, now()->diffInHours($response->json('data.expires_at')), 1);

        $step = (string) $this->verify($member, (string) $response->json('data.code'))->assertOk()->assertJsonPath('data.kind', 'reset')->json('data.step_token');
        $done = $this->complete($step, ['accepted_terms_version' => null])->assertOk();

        $fresh = $portalUser->fresh();
        $this->assertSame(PortalUser::STATUS_ACTIVE, $fresh->status);
        $this->assertSame(0, $fresh->failed_attempts);
        $this->portalJson('GET', '/api/portal/auth/me', [], $oldSession)->assertStatus(401);
        $this->portalJson('GET', '/api/portal/auth/me', [], $done->json('data.token'))->assertOk();
        $this->assertSame(1, $portalUser->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal_password_reset', 'actor_type' => 'member']);
    }
}
