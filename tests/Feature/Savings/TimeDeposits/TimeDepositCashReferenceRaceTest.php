<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\TimeDeposit;
use App\Services\GeneratedNumberWrite;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A cash placement's TD number on two real connections (finish run round 2,
 * decision 5): a placement waits for another save holding its series row,
 * one that waits too long answers 409 with nothing placed, and the number it
 * would have taken is the next placement's.
 */
class TimeDepositCashReferenceRaceTest extends TestCase
{
    use TimeDepositFixtures;

    /** The other connection can only see rows this one has committed. */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'td_cash_reference_race';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTimeDeposits();

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
        DB::connection(self::OTHER_CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        DB::connection(self::OTHER_CONNECTION)->rollBack(0);
        DB::purge(self::OTHER_CONNECTION);
        $this->tearDownTimeDeposits();

        parent::tearDown();
    }

    public function test_a_cash_placement_waits_for_a_save_holding_its_series_and_never_takes_its_number(): void
    {
        $first = $this->place()->assertCreated()->json('data.reference');
        $this->assertMatchesRegularExpression('/^TD-\d{4,}-2026-0001$/', $first);

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $other = DB::connection(self::OTHER_CONNECTION);
        $other->beginTransaction();
        $other->table('cash_reference_sequences')->where('prefix', 'TD')->lockForUpdate()->first();

        $this->place()->assertStatus(409)->assertJsonPath('message', GeneratedNumberWrite::SAVINGS_CONFLICT);
        $this->assertSame(1, TimeDeposit::query()->count());

        $other->rollBack();
        $this->place()->assertCreated()->assertJsonPath('data.reference', substr($first, 0, -4).'0002');
    }
}
