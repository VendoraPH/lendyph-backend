<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PortalUser;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;

/**
 * Remembered sign-ins still open are cut to the normal session length when
 * "Remember me" goes (finish run round 7, Pepito's QA task 14zfbg0eqfd): every
 * staff token that expires beyond now plus AUTH_TOKEN_TIMEOUT, or never, is
 * capped at that limit, with one audited system row. No token is revoked, a
 * member portal token is never touched, and a second run changes nothing.
 */
class RememberedSignInsCapMigrationTest extends TestCase
{
    use PortalTestHelpers;

    private const ACTION = 'remembered_sign_ins_shortened';

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_26_830000_cap_remembered_sign_ins.php');
    }

    private function staffToken(User $user, ?Carbon $expiresAt): PersonalAccessToken
    {
        return $user->createToken('auth-token', ['*'], $expiresAt)->accessToken;
    }

    public function test_remembered_sign_ins_are_capped_at_the_normal_session_length_with_one_system_row(): void
    {
        $this->travelTo(Carbon::parse('2026-11-26 09:00:00'));
        $user = User::where('username', 'super_admin')->firstOrFail();

        $remembered = $this->staffToken($user, now()->addDays(29));
        $neverExpires = $this->staffToken($user, null);
        $normal = $this->staffToken($user, now()->addMinutes(12));
        $atTheLimit = $this->staffToken($user, now()->addMinutes(30));
        $expired = $this->staffToken($user, now()->subDay());
        $portal = $this->portalMember()->createToken('Test device', [PortalUser::ABILITY], now()->addMinutes(60))->accessToken;
        $tokens = PersonalAccessToken::count();

        $this->migration()->up();

        $limit = '2026-11-26 09:30:00';
        $this->assertSame($limit, $remembered->fresh()->expires_at->toDateTimeString());
        $this->assertSame($limit, $neverExpires->fresh()->expires_at->toDateTimeString());
        $this->assertSame('2026-11-26 09:12:00', $normal->fresh()->expires_at->toDateTimeString());
        $this->assertSame($limit, $atTheLimit->fresh()->expires_at->toDateTimeString());
        $this->assertSame('2026-11-25 09:00:00', $expired->fresh()->expires_at->toDateTimeString());
        $this->assertSame('2026-11-26 10:00:00', $portal->fresh()->expires_at->toDateTimeString());
        $this->assertSame($tokens, PersonalAccessToken::count(), 'No token is revoked.');

        $rows = AuditLog::where('action', self::ACTION)->get();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->user_id);
        $this->assertNull($rows[0]->auditable_type);
        $this->assertSame(['shortened' => 2, 'session_minutes' => 30], $rows[0]->new_values);
        $this->assertSame('System change: 2 remembered sign-ins shortened to the normal session length (30 minutes).', $rows[0]->description);
    }

    public function test_a_second_run_changes_nothing_and_writes_no_row(): void
    {
        $this->travelTo(Carbon::parse('2026-11-26 09:00:00'));
        $remembered = $this->staffToken(User::where('username', 'super_admin')->firstOrFail(), now()->addDays(29));

        $this->migration()->up();
        $this->travelTo(Carbon::parse('2026-11-26 09:05:00'));
        $this->migration()->up();

        $this->assertSame('2026-11-26 09:30:00', $remembered->fresh()->expires_at->toDateTimeString());
        $this->assertSame(1, AuditLog::where('action', self::ACTION)->count());
    }

    public function test_nothing_to_shorten_writes_no_row(): void
    {
        $this->staffToken(User::where('username', 'super_admin')->firstOrFail(), now()->addMinutes(30));

        $this->migration()->up();

        $this->assertSame(0, AuditLog::where('action', self::ACTION)->count());
    }

    public function test_the_session_length_follows_the_token_timeout(): void
    {
        config()->set('auth.token_timeout', 45);
        $this->travelTo(Carbon::parse('2026-11-26 09:00:00'));
        $remembered = $this->staffToken(User::where('username', 'super_admin')->firstOrFail(), now()->addDays(29));

        $this->migration()->up();

        $this->assertSame('2026-11-26 09:45:00', $remembered->fresh()->expires_at->toDateTimeString());
    }
}
