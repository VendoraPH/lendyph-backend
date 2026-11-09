<?php

namespace Tests\Feature\Ledger\Gaps;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\GCashTransaction;
use App\Models\ShareCapitalLedger;
use App\Services\Accounting\LedgerGapsReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * `accounting:ledger-gaps-report`: the same rows and totals as
 * `GET /accounting/ledger-gaps`, for the whole organisation, read only.
 */
class LedgerGapsCommandTest extends TestCase
{
    use BuildsLedgerGaps, PostsJournals, SetupLendyPH;

    private const COMMAND = 'accounting:ledger-gaps-report';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** @param  array<string, mixed>  $parameters */
    private function runCommand(array $parameters = [], int $expectedExit = Command::SUCCESS): string
    {
        $exitCode = Artisan::call(self::COMMAND, $parameters);
        $output = Artisan::output();

        $this->assertSame($expectedExit, $exitCode, "Unexpected exit code. Output was:\n{$output}");

        return $output;
    }

    public function test_it_prints_every_branchs_rows_and_the_totals_by_kind(): void
    {
        $loan = $this->createReleasedLoan();
        $repayment = $this->pay($loan);
        $this->seedChartOfAccounts();
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $farRow = ShareCapitalLedger::factory()->create(['borrower_id' => Borrower::factory()->create(['branch_id' => $far->id])->id, 'date' => '2026-10-04', 'credit' => 1500, 'debit' => 0]);
        $walkIn = GCashTransaction::factory()->forNonMember()->create([
            'type' => 'cash_out', 'amount' => 3000, 'charge_amount' => 20, 'total_amount' => 2980,
            'status' => 'completed', 'transaction_date' => '2026-10-06 09:00:00',
        ]);

        $output = $this->runCommand();

        $this->assertStringContainsString('Read only', $output);
        $this->assertStringContainsString($loan->loan_account_number, $output);
        $this->assertStringContainsString($repayment->receipt_number, $output);
        $this->assertStringContainsString($farRow->reference, $output);
        $this->assertStringContainsString('Far Branch', $output);
        $this->assertStringContainsString($walkIn->reference_no, $output);
        $this->assertStringContainsString('₱60,000.00', $output);
        $this->assertStringContainsString('₱1,500.00', $output);
        $this->assertMatchesRegularExpression('/Share capital entry\s*\|\s*1\s*\|\s*₱1,500\.00/u', $output);
        $this->assertMatchesRegularExpression('/Release\s*\|\s*1\s*\|\s*₱60,000\.00/u', $output);
        $this->assertMatchesRegularExpression('/Events listed:\s+4\b/', $output);
    }

    public function test_kind_and_dates_narrow_the_rows(): void
    {
        $this->seedChartOfAccounts();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $september = ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'date' => '2026-09-04', 'credit' => 100, 'debit' => 0]);
        $october = ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'date' => '2026-10-04', 'credit' => 200, 'debit' => 0]);
        $cashIn = GCashTransaction::factory()->create([
            'type' => 'cash_in', 'amount' => 1000, 'charge_amount' => 20, 'total_amount' => 1020,
            'status' => 'paid', 'paid_at' => '2026-10-05 10:00:00', 'borrower_id' => $member->id,
        ]);

        $output = $this->runCommand(['--kind' => 'share_capital', '--from' => '2026-10-01', '--to' => '2026-10-31']);

        $this->assertStringContainsString($october->reference, $output);
        $this->assertStringNotContainsString($september->reference, $output);
        $this->assertStringNotContainsString($cashIn->reference_no, $output);
        $this->assertMatchesRegularExpression('/Events listed:\s+1\b/', $output);
    }

    /**
     * Chunks of two cross ties on the date (three releases with no release
     * date, five entries on one day) and the move from no date to a date:
     * every event prints once, and no chunk is read by OFFSET.
     */
    public function test_every_row_prints_once_across_chunks_read_from_the_last_key(): void
    {
        $this->app->when(LedgerGapsReport::class)->needs('$chunkSize')->give(2);
        $undated = [$this->createReleasedLoan(), $this->createReleasedLoan(), $this->createReleasedLoan()];
        DB::table('loans')->whereIn('id', array_map(fn ($loan) => $loan->id, $undated))->update(['released_at' => null]);
        $this->seedChartOfAccounts();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $entries = ShareCapitalLedger::factory()->count(5)->create(['borrower_id' => $member->id, 'date' => '2026-10-04', 'credit' => 100, 'debit' => 0]);

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $output = $this->runCommand();

        foreach ([...array_map(fn ($loan) => $loan->loan_account_number, $undated), ...$entries->pluck('reference')->all()] as $reference) {
            $this->assertSame(1, substr_count($output, $reference), "{$reference} is not printed exactly once.");
        }

        $this->assertMatchesRegularExpression('/Events listed:\s+8\b/', $output);
        $this->assertSame([], array_values(array_filter($statements, fn (string $sql): bool => preg_match('/\boffset\b/i', $sql) === 1)));
    }

    public function test_imported_loans_can_be_left_out_or_shown_alone(): void
    {
        $imported = $this->createReleasedLoan();
        $imported->update(['external_loan_no' => 'OLD-0001']);
        $here = $this->createReleasedLoan();
        $this->seedChartOfAccounts();

        $excluded = $this->runCommand(['--imported' => 'exclude']);
        $this->assertStringContainsString($here->loan_account_number, $excluded);
        $this->assertStringNotContainsString($imported->loan_account_number, $excluded);

        $only = $this->runCommand(['--imported' => 'only']);
        $this->assertStringContainsString($imported->loan_account_number, $only);
        $this->assertStringNotContainsString($here->loan_account_number, $only);

        $this->assertStringContainsString('imported', $this->runCommand(['--imported' => 'sometimes'], Command::FAILURE));
    }

    public function test_a_bad_kind_or_date_is_refused(): void
    {
        $this->seedChartOfAccounts();

        $this->assertStringContainsString('kind', $this->runCommand(['--kind' => 'nonsense'], Command::FAILURE));
        $this->assertStringContainsString('from', $this->runCommand(['--from' => '31/10/2026'], Command::FAILURE));
    }

    public function test_the_command_writes_nothing(): void
    {
        $loan = $this->createReleasedLoan();
        $this->pay($loan);
        $this->seedChartOfAccounts();
        $this->poster()->loanRelease($loan, $this->admin->id);
        $before = $this->snapshot();

        $writes = $this->writesDuring(fn () => $this->runCommand());

        $this->assertSame([], $writes);
        $this->assertEquals($before, $this->snapshot());
    }

    public function test_with_no_chart_it_says_so_and_lists_nothing(): void
    {
        $loan = $this->createReleasedLoan();

        $output = $this->runCommand();

        $this->assertStringContainsString('No chart of accounts', $output);
        $this->assertStringNotContainsString($loan->loan_account_number, $output);
    }
}
