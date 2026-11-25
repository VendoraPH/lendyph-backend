<?php

namespace Tests\Feature\Phase6\Amla\Events;

use App\Models\AmlaEvent;
use App\Models\TimeDeposit;
use App\Services\Amla\Events\Harvester;
use Illuminate\Support\Carbon;
use Tests\Feature\Savings\TimeDeposits\TimeDepositFixtures;
use Tests\TestCase;

/**
 * Time deposit events through the real placement and Settlement code (AMLA
 * design §4.4, PR #338 review): a payout, a pre-termination and both rollover
 * types, each event carrying the deposit's own settled figures and dated by
 * the settling write, never by hand-set timestamps.
 */
class AmlaTimeDepositEventsTest extends TestCase
{
    use AmlaEventTestHelpers, TimeDepositFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTimeDeposits();
        $this->monitoringOn();
        app(Harvester::class)->harvest();
        $this->travelTo(Carbon::parse('2026-11-10 10:01:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        $this->tearDownTimeDeposits();
        parent::tearDown();
    }

    private function placed(array $overrides = []): TimeDeposit
    {
        return TimeDeposit::query()->findOrFail($this->place(['principal' => '9000.00', ...$overrides])->assertCreated()->json('data.id'));
    }

    private function harvestAt(string $time): void
    {
        $this->travelTo(Carbon::parse($time, 'Asia/Manila'));
        app(Harvester::class)->harvest();
    }

    private function event(TimeDeposit $deposit, string $kind): ?AmlaEvent
    {
        return AmlaEvent::query()->where('source', 'time_deposit')->where('source_id', $deposit->id)->where('kind', $kind)->first();
    }

    public function test_a_payout_at_maturity_is_the_settled_amount_paid_out(): void
    {
        $deposit = $this->placed();
        $this->harvestAt('2026-11-10 10:05:00');
        $this->assertSame(['9000.00', 'in', false, 'cash'], [$this->event($deposit, 'time_deposit_placement')->amount, 'in', false, 'cash']);

        $this->travelTo(Carbon::parse('2027-02-08 09:00:00', 'Asia/Manila'));
        $data = $this->idem("/api/savings/time-deposits/{$deposit->id}/settle", ['date' => '2027-02-08', 'action' => 'payout', 'payout_method' => 'cash'])->assertCreated()->json('data');
        $this->harvestAt('2027-02-08 09:05:00');

        $payout = $this->event($deposit, 'time_deposit_payout');
        $this->assertSame([$data['settled_amount'], '9066.58', 'out', false, 'cash', '2027-02-08'], [$payout->amount, $payout->amount, $payout->direction, $payout->is_internal, $payout->channel, $payout->occurred_on->toDateString()]);
    }

    public function test_a_pretermination_is_its_payout(): void
    {
        $deposit = $this->placed();
        $this->travelTo(Carbon::parse('2026-12-10 09:00:00', 'Asia/Manila'));
        $data = $this->idem("/api/savings/time-deposits/{$deposit->id}/preterminate", ['date' => '2026-12-10', 'reason' => 'Member needs the money.', 'payout_method' => 'bank_transfer', 'payout_reference' => 'BDO-PT-1'])
            ->assertCreated()->json('data');
        $this->harvestAt('2026-12-10 09:05:00');

        $payout = $this->event($deposit, 'time_deposit_payout');
        $this->assertSame([$data['settled_amount'], '9007.40', 'out', false, 'bank_transfer'], [$payout->amount, $payout->amount, $payout->direction, $payout->is_internal, $payout->channel]);
    }

    public function test_rolling_everything_over_pays_nothing_out_and_the_new_deposit_is_internal(): void
    {
        $deposit = $this->placed();
        $this->travelTo(Carbon::parse('2027-02-08 09:00:00', 'Asia/Manila'));
        $new = $this->idem("/api/savings/time-deposits/{$deposit->id}/settle", ['date' => '2027-02-08', 'action' => 'rollover_all'])->assertCreated()->json('data');
        $this->harvestAt('2027-02-08 09:05:00');

        $this->assertNull($this->event($deposit, 'time_deposit_payout'));
        $rolled = AmlaEvent::query()->where('source', 'time_deposit')->where('source_id', $new['id'])->sole();
        $this->assertSame(['time_deposit_placement', true, 'time_deposit', $deposit->id, '9066.58'], [$rolled->kind, $rolled->is_internal, $rolled->linked_source, $rolled->linked_source_id, $rolled->amount]);
    }

    public function test_rolling_the_principal_over_pays_the_interest_out(): void
    {
        $deposit = $this->placed();
        $this->travelTo(Carbon::parse('2027-02-08 09:00:00', 'Asia/Manila'));
        $new = $this->idem("/api/savings/time-deposits/{$deposit->id}/settle", ['date' => '2027-02-08', 'action' => 'rollover_principal', 'payout_method' => 'gcash', 'payout_reference' => 'GC-INT-1'])->assertCreated()->json('data');
        $this->harvestAt('2027-02-08 09:05:00');

        $payout = $this->event($deposit, 'time_deposit_payout');
        $this->assertSame([$deposit->fresh()->settled_amount, '66.58', false, 'gcash'], [$payout->amount, $payout->amount, $payout->is_internal, $payout->channel]);
        $rolled = AmlaEvent::query()->where('source', 'time_deposit')->where('source_id', $new['id'])->sole();
        $this->assertSame([true, '9000.00'], [$rolled->is_internal, $rolled->amount]);
    }
}
