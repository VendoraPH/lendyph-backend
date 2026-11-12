<?php

namespace Tests\Feature\Savings\Rules;

use App\Models\Branch;
use App\Models\TimeDeposit;
use Illuminate\Support\Carbon;
use Tests\Feature\Savings\TimeDeposits\TimeDepositFixtures;
use Tests\TestCase;

/**
 * A time deposit product's eligible member types and branches (gap round
 * S1): a placement for a member it isn't offered to is refused (422 on
 * `product_id`) and writes nothing; an eligible one is placed. A payout into
 * savings is never stopped by the savings product's maximum balance or
 * allow-deposits switch: that money isn't a member deposit.
 */
class PlacementEligibilityTest extends TestCase
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

    public function test_a_placement_for_another_member_type_or_branch_is_refused(): void
    {
        $this->product->forceFill(['eligible_member_types' => ['associate']])->save();
        $this->place()->assertUnprocessable()->assertJsonValidationErrors('product_id');

        $this->product->forceFill(['eligible_member_types' => null, 'eligible_branch_ids' => [Branch::factory()->create()->id]])->save();
        $this->place()->assertUnprocessable()
            ->assertJsonPath('errors.product_id.0', "{$this->product->name} is not offered in {$this->branch->name}.");
        $this->assertSame(0, TimeDeposit::query()->count());

        $this->product->forceFill(['eligible_branch_ids' => [$this->branch->id]])->save();
        $this->place()->assertCreated();
    }

    public function test_a_payout_into_savings_ignores_the_maximum_balance_and_allow_deposits(): void
    {
        $account = $this->savingsAccount(100_000);
        $this->regular->forceFill(['max_balance' => '1000.00', 'allow_deposits' => false])->save();
        $deposit = TimeDeposit::query()->findOrFail($this->place(['payout_account_id' => $account->id])->assertCreated()->json('data.id'));
        $this->travelTo(Carbon::parse('2027-02-08 09:00:00', 'Asia/Manila'));

        $this->idem("/api/savings/time-deposits/{$deposit->id}/settle", ['date' => now()->toDateString(), 'action' => 'payout', 'payout_method' => 'savings'])
            ->assertCreated();

        $this->assertGreaterThan(100_000, (float) $account->refresh()->ledger_balance);
    }
}
