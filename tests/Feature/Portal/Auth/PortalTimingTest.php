<?php

namespace Tests\Feature\Portal\Auth;

use App\Models\Borrower;
use App\Models\PortalCode;
use App\Models\PortalUser;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Review M1: the time an answer takes must not tell whether an account
 * exists or what state it is in. Wall-clock time is not reliable in a test,
 * so this counts the bcrypt work instead: every refusal path of sign-in and
 * of code verification runs exactly one Hash::check and no Hash::make, and
 * the stand-in hash is built once and kept, not rebuilt per request.
 */
class PortalTimingTest extends TestCase
{
    use PortalTestHelpers, SetupLendyPH;

    private object $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->hasher = new class(app('hash'))
        {
            public int $checks = 0;

            public int $makes = 0;

            public function __construct(private readonly object $inner) {}

            public function check(mixed $value, mixed $hashed, array $options = []): bool
            {
                $this->checks++;

                return $this->inner->check($value, $hashed, $options);
            }

            public function make(mixed $value, array $options = []): string
            {
                $this->makes++;

                return $this->inner->make($value, $options);
            }

            public function __call(string $method, array $arguments): mixed
            {
                return $this->inner->{$method}(...$arguments);
            }
        };
    }

    /** Count the hashing one request does. @return array{0: int, 1: int, 2: int} */
    private function measure(string $uri, array $body): array
    {
        $this->hasher->checks = 0;
        $this->hasher->makes = 0;
        Hash::swap($this->hasher);

        $status = $this->portalJson('POST', $uri, $body)->status();

        return [$status, $this->hasher->checks, $this->hasher->makes];
    }

    public function test_every_sign_in_path_runs_exactly_one_password_check(): void
    {
        $active = $this->portalMember();
        $paths = [
            'unknown number' => ['NOBODY-404', 401],
            'not activated' => [$this->memberNumber($this->portalMember([], ['password' => null, 'status' => PortalUser::STATUS_INVITED])), 401],
            'locked for 15 minutes' => [$this->memberNumber($this->portalMember([], ['locked_until' => now()->addMinutes(10)])), 401],
            'locked until staff unlock' => [$this->memberNumber($this->portalMember([], ['status' => PortalUser::STATUS_LOCKED])), 401],
            'disabled, wrong password' => [$this->memberNumber($this->portalMember([], ['status' => PortalUser::STATUS_DISABLED])), 401, 'Wrong-pass-1'],
            'disabled, right password' => [$this->memberNumber($this->portalMember([], ['status' => PortalUser::STATUS_DISABLED])), 403],
            'inactive member, right password' => [$this->memberNumber($this->portalMember(['status' => 'inactive'])), 403],
            'wrong password' => [$this->memberNumber($active), 401, 'Wrong-pass-2'],
            'right password' => [$this->memberNumber($active), 200],
        ];

        // The stand-in hash is built by the first refusal at most, once.
        $this->measure('/api/portal/auth/login', ['member_number' => 'WARM-UP', 'password' => 'Anything-1']);

        foreach ($paths as $name => $path) {
            [$status, $checks, $makes] = $this->measure('/api/portal/auth/login', [
                'member_number' => $path[0],
                'password' => $path[2] ?? $this->portalPassword(),
            ]);

            $this->assertSame($path[1], $status, "{$name}: status");
            $this->assertSame(1, $checks, "{$name}: Hash::check calls");
            $this->assertSame(0, $makes, "{$name}: Hash::make calls");
        }
    }

    public function test_every_code_verification_path_runs_exactly_one_code_check(): void
    {
        $invite = function (): array {
            $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'birthdate' => '1985-10-10', 'status' => 'active']);
            $code = (string) $this->actingAs($this->admin)
                ->postJson("/api/portal-admin/members/{$member->id}/invite", ['identity_checked_as' => 'umid'])
                ->assertCreated()->json('data.code');

            return [$member->fresh()->membership_number ?: $member->borrower_code, $code];
        };

        [$number, $code] = $invite();
        [$expiredNumber, $expiredCode] = $invite();
        PortalCode::query()->latest('id')->firstOrFail()->forceFill(['expires_at' => now()->subMinute()])->save();
        $noCode = $this->portalMember([], ['password' => null, 'status' => PortalUser::STATUS_INVITED]);

        $paths = [
            'unknown number' => ['NOBODY-404', $code, '1985-10-10'],
            'no open code' => [$this->memberNumber($noCode), $code, '1990-05-17'],
            'expired code' => [$expiredNumber, $expiredCode, '1985-10-10'],
            'wrong code' => [$number, 'AAAA-BBBB-CCCC', '1985-10-10'],
            'wrong birthdate' => [$number, $code, '1970-01-01'],
            'dashes only' => [$number, '----', '1985-10-10'],
        ];

        $this->measure('/api/portal/auth/codes/verify', ['member_number' => 'WARM-UP', 'code' => 'x', 'birthdate' => '2000-01-01']);

        foreach ($paths as $name => [$memberNumber, $tried, $birthdate]) {
            [$status, $checks, $makes] = $this->measure('/api/portal/auth/codes/verify', [
                'member_number' => $memberNumber,
                'code' => $tried,
                'birthdate' => $birthdate,
            ]);

            $this->assertSame(422, $status, "{$name}: status");
            $this->assertSame(1, $checks, "{$name}: Hash::check calls");
            $this->assertSame(0, $makes, "{$name}: Hash::make calls");
        }
    }

    /**
     * Re-check: the stand-in hash survives a cache that keeps nothing between
     * requests (the `array` store): it is stored with the portal settings, so
     * a second request builds no new one.
     */
    public function test_the_stand_in_hash_is_kept_whatever_the_cache_store(): void
    {
        config(['cache.default' => 'array']);
        $body = ['member_number' => 'NOBODY-1', 'password' => 'Anything-1'];

        $this->measure('/api/portal/auth/login', $body);
        $this->assertNotNull(DB::table('portal_settings')->value('auth_dummy_hash'));

        Cache::flush();
        [, $checks, $makes] = $this->measure('/api/portal/auth/login', $body);

        $this->assertSame(1, $checks);
        $this->assertSame(0, $makes, 'The second request reused the stored hash.');
    }
}
