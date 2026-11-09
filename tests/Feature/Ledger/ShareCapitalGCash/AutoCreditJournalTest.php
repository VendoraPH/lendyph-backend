<?php

namespace Tests\Feature\Ledger\ShareCapitalGCash;

use App\Models\AutoCreditRun;
use App\Models\Borrower;
use App\Models\ShareCapitalLedger;
use App\Services\GeneratedNumberWrite;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * An auto-credit run in the books (Phase 2, decision 5): each member's
 * credit posts as a contribution, one journal per member row, through the
 * settlement account picked on the run (`method`, Bank by default, like
 * auto-pay), in the run's own transaction.
 */
class AutoCreditJournalTest extends TestCase
{
    use PostsJournals, ReadsJournals, SetupLendyPH;

    private Borrower $first;

    private Borrower $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->travelTo(Carbon::parse('2026-11-09 10:00:00'));

        $this->first = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->second = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->first->shareCapitalPledge->update(['amount' => 500, 'auto_credit' => true]);
        $this->second->shareCapitalPledge->update(['amount' => 1250.75, 'auto_credit' => true]);
    }

    private function rowOf(Borrower $member): ShareCapitalLedger
    {
        return ShareCapitalLedger::where('borrower_id', $member->id)->sole();
    }

    public function test_a_run_defaults_to_the_bank_and_posts_one_journal_per_member(): void
    {
        $this->postJson('/api/auto-credit/process')->assertCreated()->assertJsonPath('data.member_count', 2);

        $this->assertCount(2, $this->journalsFrom('share_capital'));

        $first = $this->rowOf($this->first);
        $this->assertSame('bank_transfer', $first->method);
        $this->assertNull($first->reference_number);
        $journal = $this->journalOn($first, 'share_capital');
        $this->assertSame(['1040 Dr 50000', '3060 Cr 50000'], $this->linesOf($journal));
        $this->assertSame('2026-11-09', $journal->date->toDateString());
        $this->assertSame($this->branch->id, $journal->branch_id);

        $this->assertSame(['1040 Dr 125075', '3060 Cr 125075'], $this->linesOf($this->journalOn($this->rowOf($this->second), 'share_capital')));
    }

    public function test_a_picked_method_and_reference_are_honoured(): void
    {
        $this->postJson('/api/auto-credit/process', ['method' => 'gcash', 'reference_number' => 'GC-RUN-11'])->assertCreated();

        foreach ([$this->first, $this->second] as $member) {
            $row = $this->rowOf($member);
            $this->assertSame('gcash', $row->method);
            $this->assertSame('GC-RUN-11', $row->reference_number);
            $this->assertSame(1, $this->journalOn($row, 'share_capital')->lines()->where('accounting_account_id', $this->account('1020'))->count());
        }
    }

    public function test_cash_needs_no_reference_and_neither_does_any_other_method(): void
    {
        $this->postJson('/api/auto-credit/process', ['method' => 'cash'])->assertCreated();

        $this->assertSame(['1010 Dr 50000', '3060 Cr 50000'], $this->linesOf($this->journalOn($this->rowOf($this->first), 'share_capital')));
    }

    public function test_a_method_outside_the_run_list_is_refused_and_nothing_is_posted(): void
    {
        foreach (['online', 'auto_pay', 'cheque'] as $method) {
            $this->postJson('/api/auto-credit/process', ['method' => $method])
                ->assertUnprocessable()->assertJsonValidationErrors('method');
        }

        $this->postJson('/api/auto-credit/process', ['method' => 'maya', 'reference_number' => str_repeat('1', 101)])
            ->assertUnprocessable()->assertJsonValidationErrors('reference_number');

        $this->assertSame(0, ShareCapitalLedger::count());
        $this->assertSame(0, AutoCreditRun::count());
        $this->assertSame(0, $this->shareCapitalJournalCount());
    }

    /** The second member's credit takes a reference already held: the run answers 409 and posts nothing. */
    public function test_a_run_that_fails_on_its_second_member_leaves_no_row_and_no_journal(): void
    {
        ShareCapitalLedger::factory()->create(['borrower_id' => $this->first->id, 'date' => '2026-11-09', 'reference' => 'SC-20261109-000003', 'credit' => 1, 'debit' => 0]);
        ShareCapitalLedger::factory()->create(['borrower_id' => $this->first->id, 'date' => '2026-11-09', 'reference' => 'SC-20261109-000001', 'credit' => 1, 'debit' => 0]);

        $this->postJson('/api/auto-credit/process')
            ->assertStatus(409)->assertExactJson(['message' => GeneratedNumberWrite::SHARE_CAPITAL_CONFLICT]);

        $this->assertSame(2, ShareCapitalLedger::count());
        $this->assertSame(0, AutoCreditRun::count());
        $this->assertSame(0, $this->shareCapitalJournalCount());
    }
}
