<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\AuditLog;
use App\Models\SavingsApproval;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\Savings\ApprovalGate;
use App\Services\Savings\SavingsLocks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Breaking a placement early (design §2, §5, §10.2): it earns the regular
 * savings rate snapshotted at placement, for the days held, with no penalty
 * in v1. The quote and the termination use one calculation.
 */
class TimeDepositPreterminationTest extends TestCase
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

    private function preterminate(TimeDeposit $deposit, array $body = [])
    {
        return $this->idem("/api/savings/time-deposits/{$deposit->id}/preterminate", [
            'date' => now()->toDateString(), 'reason' => 'Member needs the money for tuition.', 'payout_method' => 'cash', ...$body,
        ]);
    }

    public function test_the_quote_pays_the_snapshotted_regular_rate_for_the_days_held(): void
    {
        $deposit = $this->placed();
        $this->rate($this->regular, null, '2.0000', '2026-11-20');
        $this->travelTo(Carbon::parse('2026-12-10 09:00:00', 'Asia/Manila'));

        $quote = $this->getJson("/api/savings/time-deposits/{$deposit->id}/pretermination-quote?date=2026-12-10")->assertOk()->json('data');

        // ₱100,000.00 × 1% × 30 ÷ 365 = 8,219.18 centavos → ₱82.19, at the 1% in force at placement, not today's 2%.
        $this->assertSame([
            'date' => '2026-12-10',
            'days_held' => 30,
            'rate' => '1.0000',
            'gross_interest' => '82.19',
            'withholding_tax' => '0.00',
            'penalty' => '0.00',
            'payout' => '100082.19',
        ], $quote);
    }

    public function test_preterminating_within_tier_1_pays_the_quote_and_posts_once(): void
    {
        // ₱9,000.00 × 1% × 30 ÷ 365 = ₱7.40.
        $deposit = $this->placed(['principal' => '9000.00']);
        $this->travelTo(Carbon::parse('2026-12-10 09:00:00', 'Asia/Manila'));
        $quote = $this->getJson("/api/savings/time-deposits/{$deposit->id}/pretermination-quote?date=2026-12-10")->json('data');

        $data = $this->preterminate($deposit)->assertCreated()->json('data');

        $this->assertSame('preterminated', $data['status']);
        $this->assertSame('2026-12-10', $data['settled_on']);
        $this->assertSame($quote['gross_interest'], $data['settled_interest']);
        $this->assertSame($quote['payout'], $data['settled_amount']);
        $this->assertSame('9007.40', $data['settled_amount']);

        $journal = $this->journalFor(TimeDeposit::class, $deposit->id, 'time_deposit_settlement');
        $this->assertSame([['2060', 900_000, 0], ['5160', 740, 0], ['1010', 0, 900_740]], $this->lines($journal));

        $audit = AuditLog::query()->where('action', 'time_deposit_preterminated')->where('auditable_id', $deposit->id)->sole();
        $this->assertStringContainsString('Member needs the money for tuition.', $audit->description);

        $this->preterminate($deposit)->assertConflict();
    }

    public function test_a_cash_pretermination_above_tier_1_answers_202_and_a_checker_carries_it_out(): void
    {
        $deposit = $this->placed();
        $this->travelTo(Carbon::parse('2026-12-10 09:00:00', 'Asia/Manila'));

        $approval = $this->preterminate($deposit)->assertStatus(202)->json('data.approval');
        $this->assertSame('pretermination', $approval['kind']);
        $this->assertSame('100082.19', $approval['amount']);
        $this->assertSame('active', $deposit->refresh()->status);
        $this->preterminate($deposit)->assertConflict();

        $checker = User::factory()->create(['branch_id' => $this->branch->id]);
        $executor = app(ApprovalGate::class)->executor('pretermination');
        $model = SavingsApproval::query()->findOrFail($approval['id']);
        DB::transaction(function () use ($executor, $model, $checker): void {
            app(SavingsLocks::class)->accounts($executor->accountIds($model));
            app(SavingsLocks::class)->timeDeposits($executor->timeDepositIds($model));
            $executor->execute($model, $checker);
        });

        $deposit->refresh();
        $this->assertSame('preterminated', $deposit->status);
        $this->assertSame('100082.19', $deposit->settled_amount);
        $this->assertSame($checker->id, $deposit->settled_by);
    }

    public function test_on_or_after_maturity_it_must_be_settled_instead(): void
    {
        $deposit = $this->placed(['principal' => '9000.00']);

        $this->getJson("/api/savings/time-deposits/{$deposit->id}/pretermination-quote?date=2027-02-08")
            ->assertUnprocessable()->assertJsonValidationErrors('date');

        $this->travelTo(Carbon::parse('2027-02-08 09:00:00', 'Asia/Manila'));
        $this->preterminate($deposit)
            ->assertUnprocessable()
            ->assertJsonFragment(['date' => ["{$deposit->certificate_number} matures on Feb 8, 2027, so it can't be pre-terminated on or after that. Settle it instead."]]);
    }

    public function test_the_date_is_today_or_yesterday_and_never_before_placement(): void
    {
        $deposit = $this->placed(['principal' => '9000.00']);

        $this->getJson("/api/savings/time-deposits/{$deposit->id}/pretermination-quote?date=2026-11-09")
            ->assertUnprocessable()->assertJsonValidationErrors('date');

        $this->travelTo(Carbon::parse('2026-12-10 09:00:00', 'Asia/Manila'));
        $this->preterminate($deposit, ['date' => '2026-12-11'])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->preterminate($deposit, ['date' => '2026-12-08'])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->preterminate($deposit, ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('active', $deposit->refresh()->status);
    }

    public function test_a_product_with_no_pretermination_rate_pays_no_interest(): void
    {
        $this->product->forceFill(['pretermination_product_id' => null])->save();
        $deposit = $this->placed(['principal' => '9000.00']);
        $this->assertNull($deposit->pretermination_rate);
        $this->travelTo(Carbon::parse('2026-12-10 09:00:00', 'Asia/Manila'));

        $this->getJson("/api/savings/time-deposits/{$deposit->id}/pretermination-quote?date=2026-12-10")
            ->assertOk()
            ->assertJsonPath('data.rate', '0.0000')
            ->assertJsonPath('data.gross_interest', '0.00')
            ->assertJsonPath('data.payout', '9000.00');
    }
}
