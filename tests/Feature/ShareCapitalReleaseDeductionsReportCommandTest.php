<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\ShareCapitalLedger;
use App\Services\LoanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `share-capital:release-deductions-report`: the loans released before a
 * release credited the member's share capital ledger, which withheld share
 * capital that the ledger never received. Read, never written.
 */
class ShareCapitalReleaseDeductionsReportCommandTest extends TestCase
{
    use SetupLendyPH;

    private const COMMAND = 'share-capital:release-deductions-report';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_it_lists_released_loans_whose_share_capital_never_reached_the_ledger(): void
    {
        $missingReleased = $this->releasedLoanReleasedBeforeTheFix([['name' => 'Share Capital', 'amount' => 500, 'type' => 'fixed']]);
        $missingCompleted = $this->releasedLoanReleasedBeforeTheFix([
            ['name' => 'Share Capital', 'amount' => 1000.10, 'type' => 'fixed'],
            ['name' => 'share capital', 'amount' => 0.391, 'type' => 'percentage'],
        ]);
        DB::table('loans')->where('id', $missingCompleted->id)->update(['status' => 'completed']);

        $credited = $this->releasedLoan([['name' => 'Share Capital', 'amount' => 300, 'type' => 'fixed']]);
        $this->releasedLoan([['name' => 'Processing Fee', 'amount' => 2, 'type' => 'percentage']]);
        $this->approvedLoan([['name' => 'Share Capital', 'amount' => 900, 'type' => 'fixed']]);

        $output = $this->runCommand();

        // ₱500 + ₱1,000.10 + 0.391% of ₱60,000 (₱234.60) = ₱1,734.70.
        $this->assertMatchesRegularExpression('/Missing release credits:\s+2 loans, ₱1,734\.70/', $output);
        $this->assertMatchesRegularExpression('/released\s*\|\s*1\s*\|\s*₱500\.00/', $output);
        $this->assertMatchesRegularExpression('/completed\s*\|\s*1\s*\|\s*₱1,234\.70/', $output);
        $this->assertStringContainsString($missingReleased->loan_account_number, $output);
        $this->assertStringContainsString($missingCompleted->loan_account_number, $output);
        $this->assertStringNotContainsString($credited->loan_account_number, $output);
    }

    public function test_imported_loans_are_reported_apart_and_never_counted_as_missing(): void
    {
        $releasedHere = $this->releasedLoanReleasedBeforeTheFix([['name' => 'Share Capital', 'amount' => 500, 'type' => 'fixed']]);
        $imported = $this->releasedLoanReleasedBeforeTheFix([['name' => 'Share Capital', 'amount' => 321.09, 'type' => 'fixed']]);
        DB::table('loans')->where('id', $imported->id)->update(['external_loan_no' => 'OLD-0001']);

        $output = $this->runCommand();

        $this->assertMatchesRegularExpression('/Missing release credits:\s+1 loan, ₱500\.00/', $output);
        $this->assertMatchesRegularExpression('/Imported loans to check:\s+1 loan, ₱321\.09/', $output);

        [$releasedSection, $importedSection] = explode('Imported (CSV) loans', $output, 2);
        $this->assertStringContainsString($releasedHere->loan_account_number, $releasedSection);
        $this->assertStringNotContainsString($imported->loan_account_number, $releasedSection);
        $this->assertStringContainsString($imported->loan_account_number, explode('Could not be read', $importedSection)[0]);
    }

    public function test_a_loan_whose_share_capital_was_not_provable_is_listed_with_both_figures_and_in_no_total(): void
    {
        $loan = $this->approvedLoan([['name' => 'Share Capital', 'amount' => 500, 'type' => 'fixed']]);
        // Without books, ₱500 of share capital against ₱400 withheld is not
        // credited at release.
        DB::table('loans')->where('id', $loan->id)->update(['total_deductions' => 400, 'net_proceeds' => 59600]);
        $released = app(LoanService::class)->release($loan, $this->admin)->fresh();
        $this->assertSame(0, ShareCapitalLedger::query()->count());

        $output = $this->runCommand();

        $unreadable = explode('Could not be read', $output, 2)[1];
        $this->assertStringContainsString($released->loan_account_number, $unreadable);
        $this->assertStringContainsString('Not credited at release', $unreadable);
        $this->assertStringContainsString('₱500.00', $unreadable);
        $this->assertStringContainsString('₱400.00', $unreadable);
        $this->assertMatchesRegularExpression('/Missing release credits:\s+0 loans, ₱0\.00/', $output);
    }

    public function test_it_asks_whether_the_organisation_keeps_books_once_per_run(): void
    {
        foreach (range(1, 3) as $i) {
            $this->releasedLoanReleasedBeforeTheFix([['name' => 'Share Capital', 'amount' => 100 * $i, 'type' => 'fixed']]);
        }

        $chartReads = 0;
        DB::listen(function ($query) use (&$chartReads): void {
            if (str_contains($query->sql, 'from `accounting_accounts`')) {
                $chartReads++;
            }
        });

        $output = $this->runCommand();

        $this->assertSame(1, $chartReads);
        $this->assertMatchesRegularExpression('/Missing release credits:\s+3 loans, ₱600\.00/', $output);
    }

    public function test_it_reports_nothing_missing_when_every_release_was_credited(): void
    {
        $this->releasedLoan([['name' => 'Share Capital', 'amount' => 300, 'type' => 'fixed']]);

        $output = $this->runCommand();

        $this->assertMatchesRegularExpression('/Missing release credits:\s+0 loans, ₱0\.00/', $output);
    }

    public function test_it_writes_nothing(): void
    {
        $this->releasedLoanReleasedBeforeTheFix([['name' => 'Share Capital', 'amount' => 500, 'type' => 'fixed']]);
        $this->releasedLoan([['name' => 'Share Capital', 'amount' => 300, 'type' => 'fixed']]);

        $before = $this->snapshot();

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
        $this->assertEquals($before, $this->snapshot());
        $this->assertMatchesRegularExpression('/Missing release credits:\s+1 loan, ₱500\.00/', $output);
    }

    private function runCommand(): string
    {
        $exitCode = Artisan::call(self::COMMAND);

        $this->assertSame(Command::SUCCESS, $exitCode);

        return Artisan::output();
    }

    /**
     * A loan released the way releases worked before they credited the
     * ledger: released, then its release credit taken back off.
     *
     * @param  list<array{name: string, amount: float|int, type: string}>  $deductions
     */
    private function releasedLoanReleasedBeforeTheFix(array $deductions): Loan
    {
        $loan = $this->releasedLoan($deductions);

        DB::table('share_capital_ledger')->where('loan_id', $loan->id)->delete();

        return $loan;
    }

    /**
     * @param  list<array{name: string, amount: float|int, type: string}>  $deductions
     */
    private function releasedLoan(array $deductions): Loan
    {
        $loan = $this->approvedLoan($deductions);

        return app(LoanService::class)->release($loan, $this->admin)->fresh();
    }

    /**
     * @param  list<array{name: string, amount: float|int, type: string}>  $deductions
     */
    private function approvedLoan(array $deductions): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
        ]);

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $service = app(LoanService::class);

        $loan = $service->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
            'deductions' => $deductions,
        ], $this->admin);

        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');

        return $loan->fresh();
    }

    /**
     * Everything a write would move.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'ledger' => ShareCapitalLedger::query()->orderBy('id')->get()->toArray(),
            'loans' => DB::table('loans')->count(),
            'loans_updated' => DB::table('loans')->max('updated_at'),
        ];
    }
}
