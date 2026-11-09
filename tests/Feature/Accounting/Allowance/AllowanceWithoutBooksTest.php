<?php

namespace Tests\Feature\Accounting\Allowance;

use App\Models\AccountingAllowancePosting;
use App\Models\AccountingJournal;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * An organisation that keeps no books (no chart of accounts) still sees
 * what its allowance would be, and can't post it.
 */
class AllowanceWithoutBooksTest extends TestCase
{
    use AllowanceTestHelpers, SetupLendyPH;

    public function test_the_preview_shows_the_required_amount_and_nothing_can_be_posted(): void
    {
        $this->seedAndLogin();
        $this->grantTheAllowancePermission();
        $this->period($this->agedLoan(), 10, principal: 10000);

        $preview = $this->preview();

        $this->assertSame(50_000, $preview['totals']['required']);
        $this->assertSame(0, $preview['totals']['current_balance']);
        $this->assertSame(0, $preview['unassigned_balance']);
        $this->assertFalse($preview['can_post']);
        $this->assertSame(['The books aren\'t set up yet: seed the chart of accounts first.'], $preview['blocking_reasons']);

        $this->postTheRun()
            ->assertStatus(422)
            ->assertJsonPath('message', 'The books aren\'t set up yet: seed the chart of accounts first.');

        $this->assertSame(0, AccountingAllowancePosting::count());
        $this->assertSame(0, AccountingJournal::count());
    }
}
