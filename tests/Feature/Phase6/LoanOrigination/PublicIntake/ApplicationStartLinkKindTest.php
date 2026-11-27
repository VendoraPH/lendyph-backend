<?php

namespace Tests\Feature\Phase6\LoanOrigination\PublicIntake;

use App\Models\OriginationApplication;
use App\Models\OriginationLink;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A link's Test setting switched while an application starts on it (finish
 * run round 5). Staff may switch a link between test and real only while it
 * has no applications, under a lock on the link's row
 * (OriginationLinkController::update()), and each application keeps the kind
 * of link it was started on. So a starting application copies the setting from
 * the link's row as committed, read under the same lock, never from the link
 * the request read when it began: a switch that lands first is copied, and one
 * that comes after finds the application and is refused.
 *
 * The switch lands on a second connection right before the start reads the
 * link (or, without that read, right before the application is stored), as
 * staff saving the link form would. So this file opts out of the per-test
 * transaction: the other connection only sees committed rows.
 */
class ApplicationStartLinkKindTest extends TestCase
{
    use PublicApplyHelpers;

    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'application_start_link_kind';

    protected function setUp(): void
    {
        parent::setUp();

        // The daily code caps count from Manila midnight.
        $this->travelTo(now('Asia/Manila')->setTime(12, 0));

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
    }

    protected function tearDown(): void
    {
        DB::purge(self::OTHER_CONNECTION);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_application_started_as_staff_switch_its_link_to_real_is_real(): void
    {
        $link = $this->enableIntake(['is_test' => true]);
        $code = $this->requestCode($link)->assertOk()->json('data.test_mode.code');
        $landed = $this->asTheApplicationStarts($link, fn () => $this->other()->table('origination_links')->where('id', $link->id)->update(['is_test' => false]));

        $this->verifyCode($link, (string) $code)->assertOk();

        $this->assertTrue($landed(), 'the switch landed while the application started');
        $this->assertFalse($link->fresh()->is_test, 'the link is real now');
        $this->assertFalse(OriginationApplication::query()->where('link_id', $link->id)->sole()->is_test, 'the application takes the kind its link has');
    }

    public function test_an_application_reads_its_links_setting_under_the_lock_staff_saves_take(): void
    {
        $link = $this->enableIntake(['is_test' => true]);
        $code = $this->requestCode($link)->assertOk()->json('data.test_mode.code');
        $statements = [];

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$statements): void {
            $statements[] = ['sql' => $query, 'bindings' => $bindings, 'in_transaction' => DB::transactionLevel() > 0];
        });

        $this->verifyCode($link, (string) $code)->assertOk();

        $lock = collect($statements)->search(fn (array $s): bool => str_contains($s['sql'], 'from `origination_links`')
            && str_contains($s['sql'], 'for update')
            && in_array((string) $link->id, array_map('strval', $s['bindings']), true));
        $insert = collect($statements)->search(fn (array $s): bool => str_starts_with($s['sql'], 'insert into `origination_applications`'));

        $this->assertNotFalse($lock, 'the start locks its link\'s row');
        $this->assertNotFalse($insert, 'the application was stored');
        $this->assertLessThan($insert, $lock, 'the link is locked before the application is stored');
        $this->assertTrue($statements[$lock]['in_transaction'], 'inside the start\'s transaction');
        $this->assertTrue(OriginationApplication::query()->where('link_id', $link->id)->sole()->is_test);
    }

    private function other(): Connection
    {
        return DB::connection(self::OTHER_CONNECTION);
    }

    /**
     * Run `$change` on the other connection, where it commits at once, right
     * before the starting application first reads its link under a lock, or,
     * when it takes none, right before it is stored.
     *
     * @return Closure(): bool whether it landed
     */
    private function asTheApplicationStarts(OriginationLink $link, Closure $change): Closure
    {
        $landed = false;

        DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$landed, $link, $change): void {
            $locksTheLink = str_contains($query, 'from `origination_links`') && str_contains($query, 'for update')
                && in_array((string) $link->id, array_map('strval', $bindings), true);

            if ($landed || ! ($locksTheLink || str_starts_with($query, 'insert into `origination_applications`'))) {
                return;
            }

            $landed = true;
            $change();
        });

        return function () use (&$landed): bool {
            return $landed;
        };
    }
}
