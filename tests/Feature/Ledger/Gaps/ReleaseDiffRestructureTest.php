<?php

namespace Tests\Feature\Ledger\Gaps;

use App\Models\AccountingJournal;
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
