<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\SavingsProductRate;
use App\Models\TimeDeposit;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Tiered regular rates and time deposits (gap round S2): an early
 * termination and post-maturity interest earn the regular product's tier for
 * the placed amount. The early-termination rate is the tier for the placed
 * principal on the placement date, snapshotted on the placement
 * (`pretermination_rate`), as before. Post-maturity days earn, from each
 * day's regular ladder, the tier the maturity amount reaches (the balance
 * they are paid on), day by day as before tiers.
 *
 * The fixtures' regular product pays 1%; here it also pays 2% from
 * ₱50,000.00. The time deposit pays 3% on 90 days.
 */
class TimeDepositTierRateTest extends TestCase
{
    use TimeDepositFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTimeDeposits();
        SavingsProductRate::query()->create([
            'product_id' => $this->regular->id, 'term_days' => null, 'min_balance' => '50000.00',
            'annual_rate' => '2.0000', 'effective_from' => now()->toDateString(), 'created_by' => $this->admin->id,
        ]);
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

    public function test_the_early_termination_rate_is_the_tier_for_the_placed_amount_fixed_at_placement(): void
    {
        $large = $this->placed(['principal' => '50000.00']);
        $justBelow = $this->placed(['principal' => '49999.99']);

        $this->assertSame('2.0000', $large->pretermination_rate);
        $this->assertSame('1.0000', $justBelow->pretermination_rate);

        // The ladder changes after placement; the snapshot doesn't.
        SavingsProductRate::query()->create([
            'product_id' => $this->regular->id, 'term_days' => null, 'min_balance' => null,
            'annual_rate' => '0.5000', 'effective_from' => '2026-11-20', 'created_by' => $this->admin->id,
        ]);
        $this->travelTo(Carbon::parse('2026-12-10 09:00:00', 'Asia/Manila'));

        // ₱50,000.00 × 2% × 30 ÷ 365 = 8,219.18 centavos → ₱82.19.
        $this->getJson("/api/savings/time-deposits/{$large->id}/pretermination-quote?date=2026-12-10")->assertOk()
            ->assertJsonPath('data.rate', '2.0000')
            ->assertJsonPath('data.gross_interest', '82.19');
    }

    public function test_post_maturity_days_earn_the_tier_the_maturity_amount_reaches(): void
    {
        $account = $this->savingsAccount();
        $deposit = $this->placed(['payout_account_id' => $account->id]);

        $this->travelTo(Carbon::parse('2027-02-22 08:00:00', 'Asia/Manila'));

        // ₱100,739.73 × 2% × 14 ÷ 365 = 7,727.98 centavos → ₱77.28 (₱38.64 at the 1% base).
        $this->getJson("/api/savings/time-deposits/{$deposit->id}")->assertOk()
            ->assertJsonPath('data.post_maturity_days', 14)
            ->assertJsonPath('data.post_maturity_interest', '77.28');
    }

    public function test_a_maturity_amount_crossing_a_tier_the_principal_is_below_earns_the_higher_tier(): void
    {
        SavingsProductRate::query()->create([
            'product_id' => $this->regular->id, 'term_days' => null, 'min_balance' => '100000.00',
            'annual_rate' => '2.5000', 'effective_from' => now()->toDateString(), 'created_by' => $this->admin->id,
        ]);
        $account = $this->savingsAccount();
        $deposit = $this->placed(['principal' => '99000.00', 'term_days' => 360, 'payout_account_id' => $account->id]);

        // Early termination: the tier for the ₱99,000.00 principal, the ₱50,000.00 tier at 2%.
        $this->assertSame('2.0000', $deposit->pretermination_rate);
        // ₱99,000.00 × 3% × 360 ÷ 365 = ₱2,929.32, so it matures at ₱101,929.32, above ₱100,000.00.
        $this->assertSame('101929.32', $deposit->maturity_amount);

        $this->travelTo(Carbon::parse('2027-11-19 08:00:00', 'Asia/Manila'));

        // ₱101,929.32 × 2.5% × 14 ÷ 365 = 9,774.04 centavos → ₱97.74 (₱78.19 at the principal's 2% tier).
        $this->getJson("/api/savings/time-deposits/{$deposit->id}")->assertOk()
            ->assertJsonPath('data.post_maturity_days', 14)
            ->assertJsonPath('data.post_maturity_interest', '97.74');
    }

    public function test_a_placement_below_every_tier_keeps_the_base_rate_after_maturity(): void
    {
        $account = $this->savingsAccount();
        $deposit = $this->placed(['principal' => '9000.00', 'payout_account_id' => $account->id]);

        $this->travelTo(Carbon::parse('2027-02-22 08:00:00', 'Asia/Manila'));

        // ₱9,000.00 at 3% for 90 days matures at ₱9,066.58; × 1% × 14 ÷ 365 = 347.76 centavos → ₱3.48.
        $this->getJson("/api/savings/time-deposits/{$deposit->id}")->assertOk()
            ->assertJsonPath('data.maturity_amount', '9066.58')
            ->assertJsonPath('data.post_maturity_interest', '3.48');
    }
}
