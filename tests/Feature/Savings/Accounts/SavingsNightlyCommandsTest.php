<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\AuditLog;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsProduct;
use App\Models\SavingsSetting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The nightly savings jobs (design §6): `savings:expire` at 00:30 and
 * `savings:mark-dormant` at 00:35, Manila time. Each is safe to run twice,
 * does nothing when there are no savings rows, and audits what it changes.
 */
class SavingsNightlyCommandsTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_both_are_scheduled_after_midnight_manila_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy(fn ($e) => trim(str_replace(["'", '"'], '', explode('artisan', $e->command)[1] ?? '')));

        foreach (['savings:expire' => '30 0 * * *', 'savings:mark-dormant' => '35 0 * * *'] as $command => $cron) {
            $this->assertTrue($events->has($command), "{$command} is not scheduled");
            $this->assertSame($cron, $events[$command]->expression);
            $this->assertSame('Asia/Manila', $events[$command]->timezone);
            $this->assertTrue($events[$command]->withoutOverlapping);
        }
    }

    public function test_expire_ends_holds_past_their_date_and_approvals_past_their_day(): void
    {
        $account = $this->account();
        $old = SavingsHold::factory()->create(['account_id' => $account->id, 'expires_on' => '2026-11-09']);
        $today = SavingsHold::factory()->create(['account_id' => $account->id, 'expires_on' => '2026-11-10']);
        $open = SavingsHold::factory()->create(['account_id' => $account->id, 'expires_on' => null]);
        $stale = SavingsApproval::factory()->create(['account_id' => $account->id, 'expires_at' => '2026-11-09 23:59:59', 'live_key' => 'rev:999']);
        $fresh = SavingsApproval::factory()->create(['account_id' => $account->id, 'expires_at' => '2026-11-10 23:59:59']);

        $this->artisan('savings:expire')->assertSuccessful();
        $this->artisan('savings:expire')->assertSuccessful();

        $this->assertSame(['expired', 'active', 'active'], [$old->refresh()->status, $today->refresh()->status, $open->refresh()->status]);
        $this->assertSame(['expired', null], [$stale->refresh()->status, $stale->live_key]);
        $this->assertSame('pending', $fresh->refresh()->status);
        $this->assertSame(2, AuditLog::query()->whereIn('auditable_type', [SavingsHold::class, SavingsApproval::class])->where('action', 'like', '%expired%')->count());
    }

    public function test_the_24_month_dormancy_boundary(): void
    {
        $exactly = $this->account(['last_member_activity_on' => '2024-11-10']);
        $dayShort = $this->account(['last_member_activity_on' => '2024-11-11', 'product_id' => SavingsProduct::factory()->create()->id]);
        $neverUsed = $this->account(['last_member_activity_on' => null, 'opened_on' => '2024-11-01', 'product_id' => SavingsProduct::factory()->create()->id]);
        $frozen = $this->account(['status' => 'frozen', 'last_member_activity_on' => '2020-01-01', 'product_id' => SavingsProduct::factory()->create()->id]);

        $this->artisan('savings:mark-dormant')->assertSuccessful();
        $this->artisan('savings:mark-dormant')->assertSuccessful();

        $this->assertSame(['dormant', '2026-11-10'], [$exactly->refresh()->status, $exactly->dormant_since->toDateString()]);
        $this->assertSame('active', $dayShort->refresh()->status);
        $this->assertSame('dormant', $neverUsed->refresh()->status);
        $this->assertSame('frozen', $frozen->refresh()->status);
        $this->assertSame(2, AuditLog::query()->where('action', 'savings_account_dormant')->count());
    }

    public function test_dormancy_follows_the_setting(): void
    {
        SavingsSetting::current()->update(['dormancy_months' => 12]);
        $account = $this->account(['last_member_activity_on' => '2025-11-10']);

        $this->artisan('savings:mark-dormant')->assertSuccessful();

        $this->assertSame('dormant', $account->refresh()->status);
    }

    public function test_with_no_savings_rows_they_do_nothing(): void
    {
        $this->assertSame(0, SavingsAccount::query()->count());
        $before = AuditLog::query()->count();

        $this->artisan('savings:expire')->assertSuccessful();
        $this->artisan('savings:mark-dormant')->assertSuccessful();

        $this->assertSame($before, AuditLog::query()->count());
    }
}
