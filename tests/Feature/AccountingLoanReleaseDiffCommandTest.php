<?php

namespace Tests\Feature;

use App\Console\Commands\DiffLoanReleaseJournals;
use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\Accounting\JournalPoster;
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
     * A posted journal altered in the fixture — its ₱1,000.00 processing fee line moved
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

        // 4030: nothing posted, ₱1,000.00 credit due.
        $this->assertMatchesRegularExpression(
            "/{$no}\s*\|\s*{$ln}\s*\|\s*4030 [^|]+\|\s*—\s*\|\s*Cr ₱1,000\.00\s*\|\s*Cr ₱1,000\.00\s*\|/u",
            $output,
        );
        // 4040: ₱1,500.00 credit posted (its own ₱500 and the moved ₱1,000), ₱500.00 due.
        $this->assertMatchesRegularExpression(
            "/{$no}\s*\|\s*{$ln}\s*\|\s*4040 [^|]+\|\s*Cr ₱1,500\.00\s*\|\s*Cr ₱500\.00\s*\|\s*Dr ₱1,000\.00\s*\|/u",
            $output,
        );

        $this->assertStringNotContainsString($this->releaseJournalOf($untouched)->journal_no, $output);
        $this->assertMatchesRegularExpression('/Journals scanned:\s+2\b/', $output);
        $this->assertMatchesRegularExpression('/Would differ:\s+1\b/', $output);
        $this->assertMatchesRegularExpression('/Total absolute difference:\s+₱2,000\.00/', $output);
    }

    public function test_it_lists_the_deduction_types_and_which_have_an_account(): void
    {
        $this->seedChartOfAccounts();
        $this->releaseLoan();
        $this->releaseLoan();

        $output = $this->runCommand();

        $this->assertMatchesRegularExpression('/processing fee\s*\|\s*2\s*\|\s*₱2,000\.00\s*\|\s*processing_fee_income → 4030/u', $output);

        foreach ([
            'service fee' => ['₱1,000\.00', 'service_fee_income → 4040'],
            'notarial fee' => ['₱500\.00', 'notarial_fees_payable → 2030'],
            'insurance premium' => ['₱600\.00', 'insurance_premium_payable → 2040'],
        ] as $type => [$total, $mapping]) {
            $this->assertMatchesRegularExpression("/{$type}\s*\|\s*2\s*\|\s*{$total}\s*\|\s*{$mapping}/u", $output);
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

        // Only the loan that was compared counts towards the deduction types.
        $this->assertMatchesRegularExpression('/processing fee\s*\|\s*1\s*\|\s*₱1,000\.00\s*\|/u', $output);
    }

    public function test_the_header_says_journals_posted_before_a_type_had_its_own_account_may_differ(): void
    {
        $this->seedChartOfAccounts();
        $this->releaseLoan();

        $output = $this->runCommand();

        $this->assertStringContainsString(
            'Deduction types with an account of their own are booked to it, so journals posted before that may differ by design.',
            $output,
        );
    }

    /**
     * A total above its items and items above their total are reported apart,
     * so one cannot hide the other; an item with no usable amount is listed
     * rather than silently dropped.
     */
    public function test_remainders_either_way_and_unusable_items_are_reported_separately(): void
    {
        $this->seedChartOfAccounts();
        $short = $this->releaseLoan();
        $over = $this->releaseLoan();

        // ₱2,050 withheld; the Service Fee item (₱500) is gone from the list.
        DB::table('loans')->where('id', $short->id)->update(['deductions' => json_encode(array_values(array_filter(
            $short->deductions,
            static fn (array $item): bool => $item['name'] !== 'Service Fee',
        )))]);

        // ₱2,050 withheld; a ₱700 item it never charged, and one with no amount.
        DB::table('loans')->where('id', $over->id)->update(['deductions' => json_encode([
            ...$over->deductions,
            ['name' => 'Documentary Stamp', 'amount' => 700, 'type' => 'fixed'],
            ['name' => 'Legal Fee', 'type' => 'fixed'],
        ])]);

        $output = $this->runCommand();

        $this->assertMatchesRegularExpression('/Total deductions above their usable items[^:]*:\s+1 loan\(s\), ₱500\.00/u', $output);
        $this->assertMatchesRegularExpression('/Usable items above total deductions:\s+1 loan\(s\), ₱700\.00/u', $output);
        $this->assertMatchesRegularExpression(
            '/legal fee \(unusable item: no usable amount\)\s*\|\s*1\s*\|\s*—\s*\|/u',
            $output,
        );
        // The short loan's missing Service Fee item leaves its ₱500 in the
        // remainder, which the rule books to 4030 rather than the 4040 its
        // journal carries.
        $this->assertMatchesRegularExpression('/Would differ:\s+1\b/', $output);
        $this->assertMatchesRegularExpression('/Skipped:\s+0\b/', $output);
    }

    /**
     * Only POSTED release journals are compared. A reversed one is history
     * with its own reversal beside it, and is left out even when its lines
     * would differ.
     */
    public function test_a_reversed_release_journal_is_not_scanned(): void
    {
        $this->seedChartOfAccounts();
        $this->releaseLoan();
        $reversed = $this->releaseJournalOf($this->releaseLoan());

        DB::table('accounting_journal_lines')
            ->where('accounting_journal_id', $reversed->id)
            ->where('accounting_account_id', $this->account('4030'))
            ->update(['accounting_account_id' => $this->account('4040')]);
        app(JournalPoster::class)->reverse($reversed->fresh(), now()->toDateString(), 'Test reversal', $this->admin->id);

        $output = $this->runCommand();

        $this->assertSame('reversed', $reversed->fresh()->status);
        $this->assertMatchesRegularExpression('/Journals scanned:\s+1\b/', $output);
        $this->assertMatchesRegularExpression('/Would differ:\s+0\b/', $output);
        $this->assertStringNotContainsString($reversed->journal_no, $output);
    }

    /**
     * Journals are read a chunk at a time. With the chunk lowered to two, five
     * journals take three chunks, and the one altered in the last chunk is
     * still found — nothing past the first chunk is dropped or read twice.
     */
    public function test_every_chunk_is_compared(): void
    {
        $this->seedChartOfAccounts();

        $loans = [];
        foreach (range(1, 5) as $ignored) {
            $loans[] = $this->releaseLoan();
        }

        $last = $this->releaseJournalOf($loans[4]);
        DB::table('accounting_journal_lines')
            ->where('accounting_journal_id', $last->id)
            ->where('accounting_account_id', $this->account('4030'))
            ->update(['accounting_account_id' => $this->account('4040')]);

        $this->app->when(DiffLoanReleaseJournals::class)->needs('$chunkSize')->give(2);

        $loanReads = 0;
        DB::listen(function ($query) use (&$loanReads): void {
            if (preg_match('/^select `id`, `loan_account_number`.* from `loans` where `id` in/', $query->sql) === 1) {
                $loanReads++;
            }
        });

        $output = $this->runCommand();

        // One read of loans per chunk: 2 + 2 + 1.
        $this->assertSame(3, $loanReads);
        $this->assertMatchesRegularExpression('/Journals scanned:\s+5\b/', $output);
        $this->assertMatchesRegularExpression('/Would differ:\s+1\b/', $output);
        $this->assertMatchesRegularExpression('/Matching:\s+4\b/', $output);
        $this->assertStringContainsString($last->journal_no, $output);
        $this->assertMatchesRegularExpression('/processing fee\s*\|\s*5\s*\|\s*₱5,000\.00\s*\|/u', $output);
    }

    public function test_an_organisation_with_no_books_has_nothing_to_compare(): void
    {
        $this->releaseLoan();

        $output = $this->runCommand();

        $this->assertStringContainsString('No chart of accounts', $output);
        $this->assertSame(0, DB::table('accounting_journals')->count());
    }
}
