<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\AccountingJournal;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `savings:mature-time-deposits` at 00:40 Manila (design §6): due placements
 * move from active to matured; it settles by itself only what moves no cash
 * (a full rollover, or a payout into the member's savings account), as the
 * system. Run twice, it posts once.
 */
class TimeDepositMaturityJobTest extends TestCase
{
    use TimeDepositFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTimeDeposits();
    }

    protected function tearDown(): void
    {
        $this->tearDownTimeDeposits();
        parent::tearDown();
    }

    private function placed(array $overrides = []): TimeDeposit
    {
        return TimeDeposit::query()->findOrFail($this->place($overrides)->assertCreated()->json('data.id'));
    }

    private function settlementJournals(): int
    {
        return AccountingJournal::query()->where('postable_type', TimeDeposit::class)->where('source', 'time_deposit_settlement')->count();
    }

    public function test_running_twice_matures_and_settles_once(): void
    {
        $account = $this->savingsAccount();
        $rollover = $this->placed(['maturity_instruction' => 'rollover_all']);
        $cash = $this->placed(['maturity_instruction' => 'payout']);
        $intoSavings = $this->placed(['maturity_instruction' => 'payout', 'payout_account_id' => $account->id]);
        $interestInCash = $this->placed(['maturity_instruction' => 'rollover_principal']);
        $later = $this->placed(['term_days' => 180]);

        $this->travelTo(Carbon::parse('2027-02-08 00:40:00', 'Asia/Manila'));

        $this->artisan('savings:mature-time-deposits')->assertSuccessful();
        $this->artisan('savings:mature-time-deposits')->assertSuccessful();

        $this->assertSame('rolled_over', $rollover->refresh()->status);
        $this->assertNull($rollover->settled_by);
        $this->assertSame(1, TimeDeposit::query()->where('rolled_over_from_id', $rollover->id)->count());
        $this->assertSame('matured', $cash->refresh()->status);
        $this->assertSame('withdrawn', $intoSavings->refresh()->status);
        $this->assertSame('matured', $interestInCash->refresh()->status);
        $this->assertSame('active', $later->refresh()->status);

        $this->assertSame(2, $this->settlementJournals());
        $this->assertSame(1, SavingsTransaction::query()->where('account_id', $account->id)->count());
        $this->assertSame('100739.73', $account->refresh()->ledger_balance);

        $journal = $this->journalFor(TimeDeposit::class, $rollover->id, 'time_deposit_settlement');
        $this->assertNull($journal->created_by);
    }

    public function test_a_system_settlement_reads_as_the_system(): void
    {
        $rollover = $this->placed(['maturity_instruction' => 'rollover_all']);
        $this->travelTo(Carbon::parse('2027-02-08 00:40:00', 'Asia/Manila'));
        $this->artisan('savings:mature-time-deposits')->assertSuccessful();

        $this->getJson("/api/savings/time-deposits/{$rollover->id}")
            ->assertOk()
            ->assertJsonPath('data.settled_by', null)
            ->assertJsonPath('data.status', 'rolled_over');
    }

    public function test_a_matured_cash_payout_is_still_settled_by_staff(): void
    {
        $cash = $this->placed();
        $this->travelTo(Carbon::parse('2027-02-09 00:40:00', 'Asia/Manila'));
        $this->artisan('savings:mature-time-deposits')->assertSuccessful();
        $this->assertSame('matured', $cash->refresh()->status);

        $this->travelTo(Carbon::parse('2027-02-09 10:00:00', 'Asia/Manila'));
        $this->idem("/api/savings/time-deposits/{$cash->id}/settle", ['date' => '2027-02-09', 'action' => 'payout', 'payout_method' => 'cash'])
            ->assertStatus(202);
    }

    public function test_a_rollover_that_cannot_be_placed_stays_matured_and_the_run_carries_on(): void
    {
        $other = SavingsProduct::factory()->timeDeposit()->create();
        $this->rate($other, 90, '3.0000');
        $stuck = $this->placed(['maturity_instruction' => 'rollover_all']);
        $fine = $this->placed(['maturity_instruction' => 'rollover_all', 'product_id' => $other->id]);
        $this->product->forceFill(['is_active' => false])->save();

        $this->travelTo(Carbon::parse('2027-02-08 00:40:00', 'Asia/Manila'));
        $this->artisan('savings:mature-time-deposits')->assertSuccessful();

        $this->assertSame('matured', $stuck->refresh()->status);
        $this->assertNull($this->journalFor(TimeDeposit::class, $stuck->id, 'time_deposit_settlement'));
        $this->assertSame('rolled_over', $fine->refresh()->status);
        $this->assertSame(1, $this->settlementJournals());
    }

    public function test_it_does_nothing_with_no_placements(): void
    {
        $this->artisan('savings:mature-time-deposits')->assertSuccessful();
        $this->assertSame(0, TimeDeposit::query()->count());
    }

    public function test_it_is_scheduled_at_00_40_manila_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => Str::contains((string) $event->command, 'savings:mature-time-deposits'));

        $this->assertNotNull($event);
        $this->assertSame('40 0 * * *', $event->getExpression());
        $this->assertSame('Asia/Manila', $event->timezone instanceof \DateTimeZone ? $event->timezone->getName() : $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }
}
