<?php

namespace Tests\Feature\Ledger\Gaps;

use App\Models\AccountingJournal;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\PostingRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * `accounting:loan-release-diff` rebuilds a restructure with the restructure
 * rule (J1, and J2's principal written off) and an ordinary release with the
 * method it was released by. A restructure released before its split was
 * stored can't be rebuilt, so it is skipped and left to the gaps report.
 *
 * Loans are released before the chart is seeded, so each test's books hold
 * only the journals it posts.
 */
class ReleaseDiffRestructureTest extends TestCase
{
    use BuildsLedgerGaps, PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function diff(): string
    {
        $exitCode = Artisan::call('accounting:loan-release-diff', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode, "Unexpected exit code. Output was:\n{$output}");

        return $output;
    }

    public function test_an_ordinary_release_is_rebuilt_with_the_method_it_was_released_by(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['release_method' => 'gcash']);
        $this->seedChartOfAccounts();
        $this->poster()->loanRelease($loan->fresh(), $this->admin->id, 'gcash');

        $output = $this->diff();

        $this->assertMatchesRegularExpression('/Journals scanned:\s+1\b/', $output);
        $this->assertMatchesRegularExpression('/Would differ:\s+0\b/', $output);
        $this->assertMatchesRegularExpression('/Matching:\s+1\b/', $output);
    }

    public function test_a_restructure_posted_by_the_new_rule_shows_no_difference(): void
    {
        $capitalised = $this->restructureOf($this->createReleasedLoan(), $first = $this->createReleasedLoan(), [5_000_000, 700_000, 300_000, 0]);
        $writtenOff = $this->restructureOf($this->createReleasedLoan(), $second = $this->createReleasedLoan(), [6_000_000, 0, 0, 500_000]);
        $writtenOff->update(['release_method' => 'gcash']);
        $this->seedChartOfAccounts();

        $this->poster()->restructureRelease($capitalised, $first, $this->admin->id, 'cash');
        $result = $this->poster()->restructureRelease($writtenOff->fresh(), $second, $this->admin->id, 'gcash');
        $this->assertNotNull($result['write_off']);

        $output = $this->diff();

        $this->assertStringContainsString('No posted release journal differs', $output);
        $this->assertMatchesRegularExpression('/Journals scanned:\s+2\b/', $output);
        $this->assertMatchesRegularExpression('/Matching:\s+2\b/', $output);
        $this->assertMatchesRegularExpression('/Skipped:\s+0\b/', $output);
    }

    public function test_a_restructure_with_its_split_posted_as_an_ordinary_release_differs(): void
    {
        $loan = $this->restructureOf($this->createReleasedLoan(), $this->createReleasedLoan(), [5_000_000, 700_000, 300_000, 0]);
        $this->seedChartOfAccounts();
        $this->poster()->loanRelease($loan, $this->admin->id);

        $output = $this->diff();

        $this->assertMatchesRegularExpression('/Would differ:\s+1\b/', $output);
        $this->assertStringContainsString('1210', $output);
        $this->assertStringContainsString($loan->loan_account_number, $output);
    }

    public function test_a_write_off_that_lost_its_principal_credit_differs(): void
    {
        $loan = $this->restructureOf($this->createReleasedLoan(), $source = $this->createReleasedLoan(), [6_000_000, 0, 0, 500_000]);
        $this->seedChartOfAccounts();
        $writeOff = $this->poster()->restructureRelease($loan, $source, $this->admin->id, 'cash')['write_off'];

        DB::table('accounting_journal_lines')
            ->where('accounting_journal_id', $writeOff->id)
            ->where('accounting_account_id', $this->account('1110'))
            ->update(['accounting_account_id' => $this->account('1010')]);

        $output = $this->diff();

        $this->assertMatchesRegularExpression('/Would differ:\s+1\b/', $output);
        $this->assertStringContainsString($writeOff->journal_no, $output);
        $this->assertStringContainsString('₱5,000.00', $output);
    }

    public function test_a_write_off_that_debited_a_wrong_account_differs(): void
    {
        $loan = $this->restructureOf($this->createReleasedLoan(), $source = $this->createReleasedLoan(), [6_000_000, 0, 0, 500_000]);
        $this->seedChartOfAccounts();
        $writeOff = $this->poster()->restructureRelease($loan, $source, $this->admin->id, 'cash')['write_off'];

        DB::table('accounting_journal_lines')
            ->where('accounting_journal_id', $writeOff->id)
            ->where('accounting_account_id', $this->account('5140'))
            ->update(['accounting_account_id' => $this->account('4010')]);

        $output = $this->diff();

        $this->assertMatchesRegularExpression('/Would differ:\s+1\b/', $output);
        $this->assertMatchesRegularExpression('/'.preg_quote($writeOff->journal_no, '/').'.*4010/u', $output);
    }

    public function test_a_write_off_with_debits_that_dont_add_up_to_the_principal_differs(): void
    {
        $loan = $this->restructureOf($this->createReleasedLoan(), $source = $this->createReleasedLoan(), [6_000_000, 0, 0, 500_000]);
        $this->seedChartOfAccounts();
        $writeOff = $this->poster()->restructureRelease($loan, $source, $this->admin->id, 'cash')['write_off'];

        DB::table('accounting_journal_lines')
            ->where('accounting_journal_id', $writeOff->id)
            ->where('accounting_account_id', $this->account('5140'))
            ->update(['debit' => 400_000]);

        $output = $this->diff();

        $this->assertMatchesRegularExpression('/Would differ:\s+1\b/', $output);
        $this->assertStringContainsString('Debits in total', $output);
    }

    /**
     * A restructure of a restructure: J2 takes the principal it writes off
     * from the source's own deferral first, rebuilt from the source's
     * journals as it stood before J2.
     */
    public function test_a_write_off_is_checked_against_the_sources_deferral(): void
    {
        $source = $this->restructureOf($this->createReleasedLoan(), $first = $this->createReleasedLoan(), [5_000_000, 700_000, 300_000, 0]);
        $loan = $this->restructureOf($this->createReleasedLoan(), $source, [6_000_000, 0, 0, 500_000]);
        $this->seedChartOfAccounts();
        $this->poster()->restructureRelease($source, $first, $this->admin->id, 'cash');
        $writeOff = $this->poster()->restructureRelease($loan, $source->fresh(), $this->admin->id, 'cash')['write_off'];
        $this->assertSame(500_000, (int) $writeOff->lines()->where('accounting_account_id', $this->account('1210'))->sum('debit'));

        $matching = $this->diff();

        $this->assertMatchesRegularExpression('/Journals scanned:\s+2\b/', $matching);
        $this->assertMatchesRegularExpression('/Matching:\s+2\b/', $matching);

        DB::table('accounting_journal_lines')
            ->where('accounting_journal_id', $writeOff->id)
            ->where('accounting_account_id', $this->account('1210'))
            ->update(['accounting_account_id' => $this->account('1200')]);

        $moved = $this->diff();

        $this->assertMatchesRegularExpression('/Would differ:\s+1\b/', $moved);
        $this->assertMatchesRegularExpression('/'.preg_quote($writeOff->journal_no, '/').'.*1210/u', $moved);
    }

    /**
     * The source holds ₱2,000.00 of deferral and ₱5,000.00 is written off.
     * A J2 whose marked 1210 line took all ₱5,000.00 (more than the source
     * ever held) still adds up, and only the deferral read from before J2
     * shows it.
     */
    public function test_a_write_off_that_took_more_than_the_sources_deferral_differs(): void
    {
        $source = $this->restructureOf($this->createReleasedLoan(), $first = $this->createReleasedLoan(), [5_800_000, 200_000, 0, 0]);
        $loan = $this->restructureOf($this->createReleasedLoan(), $source, [6_000_000, 0, 0, 500_000]);
        $this->seedChartOfAccounts();
        $this->poster()->restructureRelease($source, $first, $this->admin->id, 'cash');
        $writeOff = $this->poster()->restructureRelease($loan, $source->fresh(), $this->admin->id, 'cash')['write_off'];
        $this->assertSame(200_000, (int) $writeOff->lines()->where('accounting_account_id', $this->account('1210'))->sum('debit'));
        $this->assertMatchesRegularExpression('/Matching:\s+2\b/', $this->diff());

        DB::table('accounting_journal_lines')->where('accounting_journal_id', $writeOff->id)
            ->where('accounting_account_id', $this->account('5140'))
            ->update(['accounting_account_id' => $this->account('1210'), 'description' => PostingRules::DEFERRAL_WRITTEN_OFF_LINE]);

        $output = $this->diff();

        $this->assertMatchesRegularExpression('/Would differ:\s+1\b/', $output);
        $this->assertMatchesRegularExpression('/'.preg_quote($writeOff->journal_no, '/').'.*1210.*Dr ₱5,000\.00.*Dr ₱2,000\.00/u', $output);
        $this->assertStringNotContainsString('Debits in total', $output);
    }

    /**
     * A restructure of a restructure writing off less than the source's
     * deferral carries the rest to the new loan: J1 holds equal Dr and Cr
     * 1210 carry lines, and J1 and J2 both match their rebuild.
     */
    public function test_a_restructure_that_carries_the_rest_of_the_deferral_matches(): void
    {
        $source = $this->restructureOf($this->createReleasedLoan(), $first = $this->createReleasedLoan(), [5_000_000, 700_000, 300_000, 0]);
        $loan = $this->restructureOf($this->createReleasedLoan(), $source, [6_000_000, 0, 0, 300_000]);
        $this->seedChartOfAccounts();
        $this->poster()->restructureRelease($source, $first, $this->admin->id, 'cash');
        $result = $this->poster()->restructureRelease($loan, $source->fresh(), $this->admin->id, 'cash');

        $carriedIn = (int) $result['release']->lines()->whereIn('description', [PostingRules::DEFERRED_INTEREST_CARRIED_IN_LINE, PostingRules::DEFERRED_PENALTY_CARRIED_IN_LINE])->sum('credit');
        $carriedOut = (int) $result['release']->lines()->whereIn('description', [PostingRules::DEFERRED_INTEREST_CARRIED_OUT_LINE, PostingRules::DEFERRED_PENALTY_CARRIED_OUT_LINE])->sum('debit');
        $this->assertSame(700_000, $carriedIn);
        $this->assertSame($carriedIn, $carriedOut);
        $this->assertSame(300_000, (int) $result['write_off']->lines()->where('accounting_account_id', $this->account('1210'))->sum('debit'));

        $output = $this->diff();

        $this->assertMatchesRegularExpression('/Journals scanned:\s+2\b/', $output);
        $this->assertMatchesRegularExpression('/Matching:\s+2\b/', $output);
        $this->assertSame([], $this->rowsOf($this->gaps()->assertOk(), 'restructure'));
    }

    /** A reversed J2 is one gap, counted once: its principal, not its deferral part again. */
    public function test_a_reversed_write_off_is_counted_once(): void
    {
        $source = $this->restructureOf($this->createReleasedLoan(), $first = $this->createReleasedLoan(), [5_000_000, 700_000, 300_000, 0]);
        $loan = $this->restructureOf($this->createReleasedLoan(), $source, [6_000_000, 0, 0, 300_000]);
        $this->seedChartOfAccounts();
        $this->poster()->restructureRelease($source, $first, $this->admin->id, 'cash');
        $writeOff = $this->poster()->restructureRelease($loan, $source->fresh(), $this->admin->id, 'cash')['write_off'];
        app(JournalPoster::class)->reverse($writeOff, now()->toDateString(), 'Test', $this->admin->id);

        $output = $this->diff();

        $this->assertMatchesRegularExpression('/Would differ:\s+1\b/', $output);
        $this->assertStringContainsString('no write-off journal', $output);
        $this->assertMatchesRegularExpression('/Total absolute difference:\s+₱3,000\.00/u', $output);
    }

    public function test_a_write_off_that_cannot_be_rebuilt_is_its_own_skip_and_the_release_is_still_compared(): void
    {
        $loan = $this->restructureOf($this->createReleasedLoan(), $source = $this->createReleasedLoan(), [6_000_000, 0, 0, 500_000]);
        $this->seedChartOfAccounts();
        $this->poster()->restructureRelease($loan, $source, $this->admin->id, 'cash');
        DB::table('accounting_account_mappings')->where('role', 'allowance_credit_losses')->delete();

        $output = $this->diff();

        $this->assertMatchesRegularExpression('/Write-offs skipped:\s+1\b/', $output);
        $this->assertMatchesRegularExpression('/Matching:\s+1\b/', $output);
        $this->assertMatchesRegularExpression('/Skipped:\s+0\b/', $output);
    }

    public function test_a_restructure_released_before_its_split_was_stored_is_skipped_for_the_gaps_report(): void
    {
        $loan = $this->restructureOf($this->createReleasedLoan(), $this->createReleasedLoan());
        $this->seedChartOfAccounts();
        $this->poster()->loanRelease($loan, $this->admin->id);
        $journals = AccountingJournal::count();

        $output = $this->diff();

        $this->assertMatchesRegularExpression('/Skipped:\s+1\b/', $output);
        $this->assertMatchesRegularExpression('/Would differ:\s+0\b/', $output);
        $this->assertStringContainsString('accounting:ledger-gaps-report', $output);
        $this->assertSame($journals, AccountingJournal::count());
    }
}
