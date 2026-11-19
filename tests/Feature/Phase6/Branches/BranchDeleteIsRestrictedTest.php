<?php

namespace Tests\Feature\Phase6\Branches;

use App\Models\AccountingExpense;
use App\Models\Borrower;
use App\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE2 (multi-branch audit D10): a branch holding members,
 * journals or expenses can't be deleted in the database; deleting one used
 * to blank their branch. A branch with none of them still deletes.
 */
class BranchDeleteIsRestrictedTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private Branch $town;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->town = Branch::factory()->create();
    }

    public function test_a_branch_with_a_member_is_not_deleted(): void
    {
        Borrower::factory()->create(['branch_id' => $this->town->id]);

        $this->assertDeleteRefused();
    }

    public function test_a_branch_with_a_journal_is_not_deleted(): void
    {
        $this->seedChartOfAccounts();
        $this->draftJournal([
            ['account_id' => $this->account('5030'), 'debit' => 10000, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 10000],
        ], ['branch_id' => $this->town->id]);

        $this->assertDeleteRefused();
    }

    public function test_a_branch_with_an_expense_is_not_deleted(): void
    {
        $this->seedChartOfAccounts();
        AccountingExpense::factory()->create(['branch_id' => $this->town->id]);

        $this->assertDeleteRefused();
    }

    public function test_a_branch_with_none_of_them_still_deletes(): void
    {
        DB::table('branches')->where('id', $this->town->id)->delete();

        $this->assertNull(Branch::query()->find($this->town->id));
    }

    private function assertDeleteRefused(): void
    {
        try {
            DB::transaction(fn () => DB::table('branches')->where('id', $this->town->id)->delete());
            $this->fail('The branch was deleted.');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->getCode());
        }

        $this->assertNotNull(Branch::query()->find($this->town->id));
    }
}
