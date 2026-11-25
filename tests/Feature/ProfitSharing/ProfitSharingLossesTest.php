<?php

namespace Tests\Feature\ProfitSharing;

use App\Models\Branch;
use Illuminate\Support\Carbon;
use Tests\Feature\Ledger\Deferral\DeferralScenarios;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The Profit Sharing report's losses: the allowance for credit losses
 * provided less released, and principal written off by a restructure.
 *
 * Each loss is counted once. A write-off the allowance covered was already
 * counted when the allowance was provided, and one covered by a restructure's
 * unearned interest was never income, so only what the write-off charged to
 * expense counts, as the books post it (J2). With no books, all of the
 * principal written off counts. An expense or allowance posting whose journal
 * was reversed on or before the cutoff date is left out; a write-off whose
 * journal was reversed by then counts all of its principal as lost (the safe
 * side, money review 2026-11-24).
 */
class ProfitSharingLossesTest extends TestCase
{
    use BuildsProfitSharing, DeferralScenarios, PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-24 10:00:00', 'Asia/Manila'));
        $this->seedAndLogin();
    }

    public function test_a_write_off_the_allowance_covered_is_not_counted_twice(): void
    {
        // Released (today) before the books exist, as the deferral tests do,
        // so the releases themselves post nothing.
        $source = $this->createReleasedLoan();
        $new = $this->restructureOf($this->createReleasedLoan(), $source, 6_000_000, 0, 0, 150_000);
        $this->seedChartOfAccounts();

        // ₱1,000.00 provided in March, then a ₱1,500.00 write-off today: J2
        // takes ₱1,000.00 from the allowance and charges ₱500.00 to expense.
        $this->allowancePosting('2026-03-31', 100_000);
        $writeOff = $this->release($new, $source)['write_off'];
        $this->assertSame(100_000, $this->sumOn($writeOff, '1200', 'debit'));
        $this->assertSame(50_000, $this->sumOn($writeOff, '5140', 'debit'));

        $profit = $this->profitSharing('2026-11-24')->assertOk()->json('data.profit');

        $this->assertEquals(1000, $profit['allowance_for_credit_losses']);
        $this->assertEquals(1500, $profit['principal_written_off']);
        $this->assertEquals(1000, $profit['written_off_covered']);
        // ₱1,500.00: the books' Credit Loss Expense, not ₱2,500.00.
        $this->assertEquals(1500, $profit['losses']);
        $this->assertEquals($profit['lending_income'] - $profit['expenses'] - 1500, $profit['distributable_profit']);
    }

    public function test_a_write_off_whose_journal_was_reversed_by_the_cutoff_loses_all_its_principal(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->restructureOf($this->createReleasedLoan(), $source, 6_000_000, 0, 0, 150_000);
        $this->seedChartOfAccounts();
        $this->allowancePosting('2026-03-31', 100_000);
        $writeOff = $this->release($new, $source)['write_off'];
        $this->reverseJournal($writeOff->id, '2026-11-24');

        $profit = $this->profitSharing('2026-11-24')->assertOk()->json('data.profit');

        // The allowance posting stands; the write-off is taken as never
        // covered, so all ₱1,500.00 counts: the safe side.
        $this->assertEquals(1000, $profit['allowance_for_credit_losses']);
        $this->assertEquals(1500, $profit['principal_written_off']);
        $this->assertEquals(0, $profit['written_off_covered']);
        $this->assertEquals(2500, $profit['losses']);
    }

    public function test_without_books_all_of_the_principal_written_off_is_lost(): void
    {
        $source = $this->releasedLoan('2026-01-10 09:00:00');
        $new = $this->releasedLoan('2026-03-10 09:00:00');
        $new->update([
            'source_loan_id' => $source->id,
            'restructure_carried_principal' => '49200.00',
            'restructure_capitalised_interest' => '0.00',
            'restructure_capitalised_penalty' => '0.00',
            'restructure_written_off_principal' => '800.00',
        ]);
        $source->update(['status' => 'restructured']);

        // Released after the cutoff: not this period's loss.
        $later = $this->releasedLoan('2026-07-10 09:00:00');
        $later->update(['source_loan_id' => $this->releasedLoan('2026-01-10 09:00:00')->id, 'restructure_written_off_principal' => '300.00']);

        $profit = $this->profitSharing('2026-06-30')->assertOk()->json('data.profit');

        $this->assertEquals(0, $profit['allowance_for_credit_losses']);
        $this->assertEquals(800, $profit['principal_written_off']);
        $this->assertEquals(0, $profit['written_off_covered']);
        $this->assertEquals(800, $profit['losses']);
        $this->assertEquals(-800, $profit['distributable_profit']);
    }

    public function test_a_released_allowance_lowers_the_losses(): void
    {
        $this->seedChartOfAccounts();
        $this->allowancePosting('2026-02-28', 300_000);
        $this->allowancePosting('2026-05-31', -120_000);
        // Last year's provision is last year's loss.
        $this->allowancePosting('2025-12-31', 50_000);

        $profit = $this->profitSharing('2026-06-30')->assertOk()->json('data.profit');

        $this->assertEquals(1800, $profit['allowance_for_credit_losses']);
        $this->assertEquals(1800, $profit['losses']);
    }

    public function test_a_journal_reversed_by_the_cutoff_date_counts_as_not_posted(): void
    {
        $this->seedChartOfAccounts();
        $posting = $this->allowancePosting('2026-03-31', 100_000);
        $expense = $this->expense('2026-03-01', 30_000);
        $this->reverseJournal($posting->accounting_journal_id, '2026-04-30');
        $this->reverseJournal($expense->journal_id, '2026-04-30');

        // Before the reversals they stood.
        $before = $this->profitSharing('2026-04-15')->assertOk()->json('data.profit');
        $this->assertEquals(1000, $before['allowance_for_credit_losses']);
        $this->assertEquals(300, $before['expenses']);

        $after = $this->profitSharing('2026-05-31')->assertOk()->json('data.profit');
        $this->assertEquals(0, $after['allowance_for_credit_losses']);
        $this->assertEquals(0, $after['expenses']);
    }

    /**
     * Reversing the reversal puts the entry back in the books, so on a cutoff
     * after both it counts again; between the two it stood reversed. The
     * Expenses screen's rule, as of the cutoff (finish run round 4).
     */
    public function test_an_entry_whose_reversal_was_reversed_by_the_cutoff_counts(): void
    {
        $this->seedChartOfAccounts();
        $expense = $this->expense('2026-03-01', 30_000);
        $posting = $this->allowancePosting('2026-03-31', 100_000);

        foreach ([$expense->journal_id, $posting->accounting_journal_id] as $journalId) {
            $reversal = $this->reverseJournal($journalId, '2026-04-10');
            $this->reverseJournal($reversal->id, '2026-04-20');
        }

        $after = $this->profitSharing('2026-05-31')->assertOk()->json('data.profit');
        $this->assertEquals(300, $after['expenses']);
        $this->assertEquals(1000, $after['allowance_for_credit_losses']);

        $between = $this->profitSharing('2026-04-15')->assertOk()->json('data.profit');
        $this->assertEquals(0, $between['expenses']);
        $this->assertEquals(0, $between['allowance_for_credit_losses']);
    }

    /**
     * Only reversals dated on or before the cutoff count: a reversal reversed
     * after the cutoff still stood on it, so the expense was reversed then,
     * and counts again on a cutoff after the second reversal.
     */
    public function test_an_expense_whose_reversal_was_reversed_after_the_cutoff_stood_reversed_on_it(): void
    {
        $this->seedChartOfAccounts();
        $expense = $this->expense('2026-03-01', 30_000);
        $reversal = $this->reverseJournal($expense->journal_id, '2026-04-10');
        $this->reverseJournal($reversal->id, '2026-06-15');

        $this->assertEquals(0, $this->profitSharing('2026-05-31')->assertOk()->json('data.profit.expenses'));
        $this->assertEquals(300, $this->profitSharing('2026-06-30')->assertOk()->json('data.profit.expenses'));
    }

    public function test_every_branchs_losses_count_whatever_branch_is_sent(): void
    {
        $this->seedChartOfAccounts();
        $north = Branch::factory()->create();
        $this->allowancePosting('2026-03-31', 100_000);
        $this->allowancePosting('2026-03-31', 70_000, $north);

        $this->assertEquals(1700, $this->profitSharing('2026-06-30')->assertOk()->json('data.profit.losses'));
        $this->assertEquals(1700, $this->profitSharing('2026-06-30', $north->id)->assertOk()->json('data.profit.losses'));
    }
}
