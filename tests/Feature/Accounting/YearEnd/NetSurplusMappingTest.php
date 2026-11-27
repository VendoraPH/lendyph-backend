<?php

namespace Tests\Feature\Accounting\YearEnd;

use App\Models\AccountingAccountMapping;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The net surplus role can't be mapped to 3050 Opening Balance Equity. The
 * year's result would be mixed into the opening balances, and a close or
 * reopen would lock 3050 after its months while an opening balance locks
 * 3050 before them.
 */
class NetSurplusMappingTest extends TestCase
{
    use PostsJournals, SetupLendyPH, YearEndFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpYearEnd();
    }

    public function test_net_surplus_cannot_be_mapped_to_the_opening_balance_equity_account(): void
    {
        $before = AccountingAccountMapping::resolved()['net_surplus'];

        $this->putJson('/api/accounting/settings/account-mapping', ['net_surplus' => $this->account('3050')])
            ->assertUnprocessable()
            ->assertJsonPath('errors.net_surplus.0', "Retained earnings or net surplus can't post to 3050 Opening Balance Equity, the opening balance equity account: the year's result would be mixed into the opening balances. Choose another equity account.");

        $this->assertSame($before, AccountingAccountMapping::resolved()['net_surplus']);
    }

    public function test_net_surplus_can_still_be_mapped_to_another_equity_account(): void
    {
        $this->putJson('/api/accounting/settings/account-mapping', ['net_surplus' => $this->account('3010')])->assertOk();

        $this->assertSame($this->account('3010'), AccountingAccountMapping::resolved()['net_surplus']);
    }
}
