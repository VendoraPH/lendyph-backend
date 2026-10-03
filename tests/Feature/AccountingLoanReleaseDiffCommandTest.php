<?php

namespace Tests\Feature;

use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * `accounting:loan-release-diff`: what the release rule would post today for
 * every posted release journal, against what was posted — read, never written.
 *
 * The posted books are never corrected by this command, so the specs that
 * matter most are the two that prove it cannot: a run without `--dry-run`
 * refuses outright, and a dry run issues no write of any kind.
 */
class AccountingLoanReleaseDiffCommandTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const COMMAND = 'accounting:loan-release-diff';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * A loan released with the product's processing (2%), service (1%) and
     * notarial (0.5%) fees and a ₱300 insurance premium: ₱2,050 withheld from
     * ₱50,000.
     */
    private function releaseLoan(): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'processing_fee' => 2,
            'service_fee' => 1,
            'notarial_fee' => 0.5,
        ]);

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $loans = app(LoanService::class);

        $loan = $loans->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 50000.00,
            'start_date' => now()->toDateString(),
        ], $this->admin);

        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');
        $loans->release($loan, $this->admin, [
            'insurance_premium_percentage' => 0.6,
            'insurance_premium_amount' => 300.00,
            'insurance_payment_type' => 'full',
        ]);

        return $loan->fresh();
    }

    private function releaseJournalOf(Loan $loan): AccountingJournal
    {
        return AccountingJournal::query()
            ->where('postable_type', Loan::class)
            ->where('postable_id', $loan->id)
            ->where('source', 'loan_release')
            ->firstOrFail();
    }

    /**
     * Run the command and hand back everything it printed. The buffer, as in
     * AccountingOpeningBalancesTest, so a phrase that appears twice can be
     * asserted twice.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function runCommand(array $parameters = ['--dry-run' => true], int $expectedExit = Command::SUCCESS): string
    {
        $exitCode = Artisan::call(self::COMMAND, $parameters);
        $output = Artisan::output();

        $this->assertSame($expectedExit, $exitCode, "Unexpected exit code. Output was:\n{$output}");

        return $output;
    }

    /**
     * Everything a write would move: row counts, and the newest `updated_at`
     * of each table the command reads.
     *
     * @return array<string, mixed>
     */
    private function booksSnapshot(): array
    {
        return [
            'journals' => DB::table('accounting_journals')->count(),
            'journals_updated' => DB::table('accounting_journals')->max('updated_at'),
            'lines' => DB::table('accounting_journal_lines')->count(),
            'lines_updated' => DB::table('accounting_journal_lines')->max('updated_at'),
            'line_totals' => DB::table('accounting_journal_lines')->selectRaw('SUM(debit) d, SUM(credit) c')->first(),
            'loans_updated' => DB::table('loans')->max('updated_at'),
            'mappings' => DB::table('accounting_account_mappings')->count(),
            'accounts' => DB::table('accounting_accounts')->count(),
            'audit_logs' => DB::table('audit_logs')->count(),
        ];
    }

    public function test_without_dry_run_it_refuses_and_changes_nothing(): void
    {
        $this->seedChartOfAccounts();
        $this->releaseLoan();
        $before = $this->booksSnapshot();

        $output = $this->runCommand([], Command::FAILURE);

        $this->assertStringContainsString('only previews', $output);
        $this->assertStringContainsString('never changes', $output);
        $this->assertStringContainsString('--dry-run', $output);
        $this->assertStringNotContainsString('Journals scanned', $output);
        $this->assertEquals($before, $this->booksSnapshot());
    }

    /**
     * Not one INSERT, UPDATE or DELETE reaches the database during a dry run,
     * and nothing the command reads has moved afterwards.
     */
    public function test_a_dry_run_writes_nothing(): void
    {
        $this->seedChartOfAccounts();
        $this->releaseLoan();
        $altered = $this->releaseLoan();
        DB::table('accounting_journal_lines')
            ->where('accounting_journal_id', $this->releaseJournalOf($altered)->id)
            ->where('accounting_account_id', $this->account('4030'))
            ->update(['accounting_account_id' => $this->account('4040')]);

        $before = $this->booksSnapshot();

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $output = $this->runCommand();

        $writes = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $sql) === 1,
        ));

        $this->assertNotSame([], $statements, 'The command read nothing, so the check proves nothing.');
        $this->assertSame([], $writes);
        $this->assertEquals($before, $this->booksSnapshot());
        $this->assertMatchesRegularExpression('/Would differ:\s+1\b/', $output);
    }

    public function test_untouched_journals_report_no_difference(): void
    {
        $this->seedChartOfAccounts();
        $this->releaseLoan();
        $this->releaseLoan();

        $output = $this->runCommand();

        $this->assertMatchesRegularExpression('/Journals scanned:\s+2\b/', $output);
        $this->assertMatchesRegularExpression('/Would differ:\s+0\b/', $output);
        $this->assertMatchesRegularExpression('/Skipped:\s+0\b/', $output);
        $this->assertMatchesRegularExpression('/Total absolute difference:\s+₱0\.00/', $output);
        $this->assertStringContainsString('No posted release journal differs', $output);
    }

    /**
     * A posted journal altered in the fixture — its ₱2,050.00 fee line moved
     * from 4030 to 4040 — is reported against both accounts, with the
     * difference each would need, and every other journal stays silent.
     */
    public function test_a_journal_whose_posted_lines_differ_is_reported_with_the_difference(): void
    {
        $this->seedChartOfAccounts();
        $untouched = $this->releaseLoan();
        $altered = $this->releaseLoan();
        $alteredJournal = $this->releaseJournalOf($altered);

        DB::table('accounting_journal_lines')
            ->where('accounting_journal_id', $alteredJournal->id)
            ->where('accounting_account_id', $this->account('4030'))
            ->update(['accounting_account_id' => $this->account('4040')]);

        $output = $this->runCommand();

        $no = preg_quote($alteredJournal->journal_no, '/');
        $ln = preg_quote($altered->loan_account_number, '/');

        // 4030: nothing posted, ₱2,050.00 credit due.
        $this->assertMatchesRegularExpression(
            "/{$no}\s*\|\s*{$ln}\s*\|\s*4030 [^|]+\|\s*—\s*\|\s*Cr ₱2,050\.00\s*\|\s*Cr ₱2,050\.00\s*\|/u",
            $output,
        );
        // 4040: ₱2,050.00 credit posted, nothing due.
        $this->assertMatchesRegularExpression(
            "/{$no}\s*\|\s*{$ln}\s*\|\s*4040 [^|]+\|\s*Cr ₱2,050\.00\s*\|\s*—\s*\|\s*Dr ₱2,050\.00\s*\|/u",
            $output,
        );

        $this->assertStringNotContainsString($this->releaseJournalOf($untouched)->journal_no, $output);
        $this->assertMatchesRegularExpression('/Journals scanned:\s+2\b/', $output);
        $this->assertMatchesRegularExpression('/Would differ:\s+1\b/', $output);
        $this->assertMatchesRegularExpression('/Total absolute difference:\s+₱4,100\.00/', $output);
    }

    public function test_it_lists_the_deduction_types_and_which_have_an_account(): void
    {
        $this->seedChartOfAccounts();
        $this->releaseLoan();
        $this->releaseLoan();

        $output = $this->runCommand();

        $this->assertMatchesRegularExpression('/processing fee\s*\|\s*2\s*\|\s*₱2,000\.00\s*\|\s*processing_fee_income → 4030/u', $output);

        foreach (['service fee' => '₱1,000\.00', 'notarial fee' => '₱500\.00', 'insurance premium' => '₱600\.00'] as $type => $total) {
            $this->assertMatchesRegularExpression(
                "/{$type}\s*\|\s*2\s*\|\s*{$total}\s*\|\s*none — booked to processing_fee_income as before/u",
                $output,
            );
        }
    }

    /**
     * A loan whose figures no longer reconcile cannot be rebuilt, so its
     * journal is skipped and the reason given, rather than guessed at.
     */
    public function test_a_journal_whose_loan_no_longer_reconciles_is_skipped_with_the_reason(): void
    {
        $this->seedChartOfAccounts();
        $this->releaseLoan();
        $edited = $this->releaseLoan();

        DB::table('loans')->where('id', $edited->id)->update(['principal_amount' => 51000.00]);

        $output = $this->runCommand();

        $this->assertMatchesRegularExpression(
            '/'.preg_quote($this->releaseJournalOf($edited)->journal_no, '/').'\s*\|\s*'
            .preg_quote($edited->loan_account_number, '/').'\s*\|[^\n]*does not reconcile/u',
            $output,
        );
        $this->assertMatchesRegularExpression('/Journals scanned:\s+2\b/', $output);
        $this->assertMatchesRegularExpression('/Skipped:\s+1\b/', $output);
        $this->assertMatchesRegularExpression('/Would differ:\s+0\b/', $output);
    }

    public function test_an_organisation_with_no_books_has_nothing_to_compare(): void
    {
        $this->releaseLoan();

        $output = $this->runCommand();

        $this->assertStringContainsString('No chart of accounts', $output);
        $this->assertSame(0, DB::table('accounting_journals')->count());
    }
}
