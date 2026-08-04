<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class BackfillLastLoginAtTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function recordLogin(User $user, string $at): void
    {
        // audit_logs carries `created_at` only — there is no `updated_at` column.
        DB::table('audit_logs')->insert([
            'user_id' => null,
            'action' => 'login',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'created_at' => $at,
        ]);
    }

    public function test_it_backfills_the_most_recent_login_from_the_audit_log(): void
    {
        $user = User::where('username', 'super_admin')->firstOrFail();
        $user->forceFill(['last_login_at' => null])->saveQuietly();

        $this->recordLogin($user, '2026-07-01 08:00:00');
        $this->recordLogin($user, '2026-07-29 05:28:24');

        $this->artisan('users:backfill-last-login')->assertSuccessful();

        $this->assertSame(
            '2026-07-29 05:28:24',
            $user->fresh()->last_login_at->toDateTimeString(),
        );
    }

    public function test_it_never_overwrites_an_existing_last_login_at(): void
    {
        $user = User::where('username', 'super_admin')->firstOrFail();
        $user->forceFill(['last_login_at' => '2026-08-04 13:52:59'])->saveQuietly();

        // A newer audit row must not win — the command only fills blanks, which
        // is what makes it safe to re-run.
        $this->recordLogin($user, '2026-08-05 09:00:00');

        $this->artisan('users:backfill-last-login')->assertSuccessful();

        $this->assertSame(
            '2026-08-04 13:52:59',
            $user->fresh()->last_login_at->toDateTimeString(),
        );
    }

    public function test_dry_run_writes_nothing(): void
    {
        $user = User::where('username', 'super_admin')->firstOrFail();
        $user->forceFill(['last_login_at' => null])->saveQuietly();

        $this->recordLogin($user, '2026-07-29 05:28:24');

        $this->artisan('users:backfill-last-login', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_it_leaves_users_without_login_history_alone(): void
    {
        $user = User::where('username', 'super_admin')->firstOrFail();
        $user->forceFill(['last_login_at' => null])->saveQuietly();

        DB::table('audit_logs')->where('action', 'login')->delete();

        $this->artisan('users:backfill-last-login')->assertSuccessful();

        $this->assertNull($user->fresh()->last_login_at);
    }
}
