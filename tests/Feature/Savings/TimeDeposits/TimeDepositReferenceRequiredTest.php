<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\TimeDeposit;
use Tests\TestCase;

/**
 * Finish run round 5: a time deposit placed by bank transfer, GCash or Maya
 * needs the reference of that payment (422 in words). Cash needs none (the
 * server numbers it TD-…), nor does a placement paid from the member's own
 * savings account, a move between their savings records.
 */
class TimeDepositReferenceRequiredTest extends TestCase
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

    public function test_a_placement_by_bank_transfer_gcash_or_maya_without_a_reference_is_refused_in_words(): void
    {
        foreach (['bank_transfer' => 'bank transfer', 'gcash' => 'GCash', 'maya' => 'Maya'] as $method => $words) {
            foreach ([[], ['reference' => null], ['reference' => '  ']] as $blank) {
                $this->place(['payment_method' => $method, ...$blank])
                    ->assertUnprocessable()
                    ->assertJsonPath('errors.reference.0', "Enter the {$words} reference for this time deposit.");
            }
        }

        $this->assertSame(0, TimeDeposit::query()->count());
    }

    public function test_with_its_reference_a_non_cash_placement_saves_it(): void
    {
        $this->place(['payment_method' => 'maya', 'reference' => 'MY-TD-1'])->assertCreated()->assertJsonPath('data.reference', 'MY-TD-1');
    }

    public function test_cash_and_a_placement_paid_from_savings_need_no_reference(): void
    {
        $this->assertMatchesRegularExpression('/^TD-\d{4}-2026-0001$/', (string) $this->place()->assertCreated()->json('data.reference'));

        $account = $this->savingsAccount(15_000_000);
        $this->place(['payment_method' => 'savings', 'funding_account_id' => $account->id])->assertCreated()->assertJsonPath('data.reference', null);
    }
}
