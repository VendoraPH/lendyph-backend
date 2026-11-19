<?php

namespace Tests\Feature\Phase6\Branches;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Savings\TimeDeposits\TimeDepositFixtures;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE2 (multi-branch decision 2): a suspended branch takes no new
 * time deposit placements.
 */
class BranchStatusTimeDepositTest extends TestCase
{
    use SetupLendyPH, TimeDepositFixtures;

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

    public function test_a_suspended_branch_places_no_new_time_deposit(): void
    {
        DB::table('branches')->where('id', $this->branch->id)->update(['status' => 'suspended']);

        $this->place()
            ->assertUnprocessable()
            ->assertJsonPath('errors.borrower_id.0', 'Branch "Main Branch" (MAIN) is suspended, so no new time deposits can be placed there.');
    }
}
