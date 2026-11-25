<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CsvImportRow;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Services\Accounting\Money;
use App\Services\CsvImport\CsvImportProcessor;
use App\Services\CsvImport\CsvImportRowRedactor;
use App\Services\RepaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\BuildsCsvImports;

/**
 * `imports:paid-before-import-check`: the imported loans whose "paid before
 * the import" figure (PaidBeforeImport) is below zero, or not what their
 * import row says was paid, where the row still holds the file's figures.
 * Before 2026-10-02, extensions, restructures and term extensions deleted
 * part-paid periods and waivers erased paid penalty, so such a loan's periods
 * hold less than was paid. Read, never written, and nothing is floored.
 *
 * Every loan is BuildsCsvImports' row: ₱60,000.00 lent with ₱40,000.00 left,
 * and ₱10,800.00 interest with ₱7,200.00 left, so the file says ₱23,600.00.
 */
class PaidBeforeImportCheckCommandTest extends TestCase
{
    use BuildsCsvImports;

    private const COMMAND = 'imports:paid-before-import-check';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedForImport();
        $this->actingAs($this->admin);
    }

    public function test_it_lists_the_loans_reading_below_zero_or_short_of_the_file_and_writes_nothing(): void
    {
        $loans = $this->importLoans(['L-OK', 'L-SHORT', 'L-NEG']);

        // A part-paid period deleted, as an extension did before 2026-10-02:
        // ₱10,000.00 principal and ₱1,800.00 interest paid on it are gone.
        DB::table('amortization_schedules')->where('loan_id', $loans['L-SHORT']->id)->where('period_number', 1)->delete();

        // A payment here, then the periods' paid amounts erased under it.
        $this->postJson("/api/loans/{$loans['L-NEG']->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 1000,
            'method' => 'cash',
        ])->assertCreated();
        DB::table('amortization_schedules')->where('loan_id', $loans['L-NEG']->id)
            ->update(['principal_paid' => 0, 'interest_paid' => 0, 'penalty_paid' => 0]);

        $before = $this->snapshot();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (! preg_match('/^\s*select\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $output = $this->runCommand();

        $this->assertSame([], $writes, 'The check only reads.');
        $this->assertSame($before, $this->snapshot());

        $this->assertMatchesRegularExpression($this->row($loans['L-SHORT'], 'L-SHORT', '₱11,800\.00', '₱23,600\.00', 'Not what the file says'), $output);
        $this->assertMatchesRegularExpression($this->row($loans['L-NEG'], 'L-NEG', '-₱1,000\.00', '₱23,600\.00', 'Interest below zero: -₱1,000\.00; Not what the file says'), $output);
        $this->assertStringNotContainsString($loans['L-OK']->application_number, $output);
        $this->assertMatchesRegularExpression('/Imported loans read:\s+3/', $output);
        $this->assertMatchesRegularExpression('/Imported loans to check:\s+2 loans/', $output);
    }

    public function test_a_loan_whose_import_row_is_redacted_is_listed_only_below_zero(): void
    {
        $loans = $this->importLoans(['L-SHORT', 'L-NEG']);

        DB::table('amortization_schedules')->where('loan_id', $loans['L-SHORT']->id)->where('period_number', 1)->delete();
        $this->postJson("/api/loans/{$loans['L-NEG']->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 500,
            'method' => 'cash',
        ])->assertCreated();
        DB::table('amortization_schedules')->where('loan_id', $loans['L-NEG']->id)
            ->update(['principal_paid' => 0, 'interest_paid' => 0, 'penalty_paid' => 0]);

        app(CsvImportRowRedactor::class)->redact(CsvImportRow::query()->whereNotNull('loan_id'));

        $output = $this->runCommand();

        // Short of the file, but the file's figures are gone: nothing to compare.
        $this->assertStringNotContainsString($loans['L-SHORT']->application_number, $output);
        $this->assertMatchesRegularExpression($this->row($loans['L-NEG'], 'L-NEG', '-₱500\.00', 'Not kept', 'Interest below zero: -₱500\.00'), $output);
        $this->assertMatchesRegularExpression('/Not compared with the file \(its import row is redacted or gone\):\s+2/', $output);
        $this->assertMatchesRegularExpression('/Imported loans to check:\s+1 loan\b/', $output);
    }

    public function test_a_part_below_zero_or_any_penalty_part_is_listed_though_the_total_is_positive_and_the_row_redacted(): void
    {
        $loans = $this->importLoans(['L-WAIVED', 'L-PENALTY', 'L-OK']);

        // An old waiver erased paid penalty: a payment took the late penalty
        // charged on period 6, then the periods' paid penalty went.
        $waived = $loans['L-WAIVED'];
        app(RepaymentService::class)->applyPenalties($waived->fresh(), now());
        $this->postJson("/api/loans/{$waived->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $this->getJson("/api/loans/{$waived->id}/summary")->json('data.payoff_amount'),
            'method' => 'cash',
        ])->assertCreated();
        $penalty = (int) round((float) Repayment::query()->where('loan_id', $waived->id)->value('penalty_applied') * 100);
        $this->assertGreaterThan(0, $penalty);
        DB::table('amortization_schedules')->where('loan_id', $waived->id)->update(['penalty_paid' => 0]);

        // Paid penalty no file holds: the importer writes none.
        DB::table('amortization_schedules')->where('loan_id', $loans['L-PENALTY']->id)->where('period_number', 1)->update(['penalty_paid' => 50]);

        app(CsvImportRowRedactor::class)->redact(CsvImportRow::query()->whereNotNull('loan_id'));

        $output = $this->runCommand();

        $this->assertMatchesRegularExpression($this->row(
            $waived,
            'L-WAIVED',
            preg_quote(Money::format(2360000 - $penalty), '/'),
            'Not kept',
            'Penalty below zero: '.preg_quote(Money::format(-$penalty), '/'),
        ), $output);
        $this->assertMatchesRegularExpression($this->row(
            $loans['L-PENALTY'],
            'L-PENALTY',
            '₱23,650\.00',
            'Not kept',
            'Penalty not zero, though the import writes none: ₱50\.00',
        ), $output);
        $this->assertStringNotContainsString($loans['L-OK']->application_number, $output);
        $this->assertMatchesRegularExpression('/Imported loans to check:\s+2 loans/', $output);
    }

    public function test_the_branch_option_keeps_to_the_loans_booked_in_that_branch(): void
    {
        $loans = $this->importLoans(['L-SHORT', 'L-FAR']);
        $far = Branch::factory()->create();
        DB::table('loans')->where('id', $loans['L-FAR']->id)->update(['branch_id' => $far->id]);

        foreach ($loans as $loan) {
            DB::table('amortization_schedules')->where('loan_id', $loan->id)->where('period_number', 1)->delete();
        }

        $main = $this->runCommand(['--branch' => (string) $this->branch->id]);
        $this->assertStringContainsString($loans['L-SHORT']->application_number, $main);
        $this->assertStringNotContainsString($loans['L-FAR']->application_number, $main);
        $this->assertMatchesRegularExpression('/Imported loans to check:\s+1 loan\b/', $main);

        $farOutput = $this->runCommand(['--branch' => (string) $far->id]);
        $this->assertStringContainsString($loans['L-FAR']->application_number, $farOutput);
        $this->assertStringNotContainsString($loans['L-SHORT']->application_number, $farOutput);

        $this->assertSame(Command::INVALID, Artisan::call(self::COMMAND, ['--branch' => 'main']));
    }

    public function test_it_says_so_when_every_imported_loan_reads_as_its_file(): void
    {
        $this->importLoans(['L-OK']);

        $output = $this->runCommand();

        $this->assertStringContainsString('None.', $output);
        $this->assertMatchesRegularExpression('/Imported loans to check:\s+0 loans/', $output);
    }

    /**
     * One member and one loan per Loan No., through the real staging and
     * import pass, keyed by the file's Loan No.
     *
     * @param  list<string>  $loanNos
     * @return array<string, Loan>
     */
    private function importLoans(array $loanNos): array
    {
        $product = LoanProduct::factory()->create([
            'name' => 'Salary Loan',
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
            'min_amount' => 1000,
            'max_amount' => 1000000,
        ]);

        $run = $this->makeRun(['product_mapping' => ['Salary Loan' => $product->id]]);
        $accounts = array_map(fn (int $i): string => 'A-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), array_keys($loanNos));
        $this->makeFile($run, 'customers', array_map(fn (string $account): array => $this->customerRow($account), $accounts));
        $this->makeFile($run, 'loans', array_map(fn (string $account, string $loanNo): array => $this->loanRow($account, $loanNo), $accounts, $loanNos));

        $processor = new CsvImportProcessor;

        for ($tick = 0; $tick < 200 && ! $processor->advance($run)->idle; $tick++) {
            $run->refresh();
        }

        $this->assertSame('completed', $run->fresh()->phase);

        return Loan::query()->whereIn('external_loan_no', $loanNos)->get()->keyBy('external_loan_no')->all();
    }

    private function runCommand(array $options = []): string
    {
        $this->assertSame(Command::SUCCESS, Artisan::call(self::COMMAND, $options));

        return Artisan::output();
    }

    /** One table line: the loan's number, its file Loan No., member, figure, the file's figure and why. */
    private function row(Loan $loan, string $loanNo, string $figure, string $inFile, string $why): string
    {
        return '/'.preg_quote($loan->application_number, '/').'\s*\|\s*'.preg_quote($loanNo, '/').'\s*\|\s*'
            .preg_quote((string) $loan->borrower->borrower_code, '/').'\s*\|\s*'.$figure.'\s*\|\s*'.$inFile.'\s*\|\s*'.$why.'/u';
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        return [
            'loans' => DB::table('loans')->count(),
            'schedules' => (int) DB::table('amortization_schedules')->sum(DB::raw('principal_paid * 100 + interest_paid * 100 + penalty_paid * 100')),
            'repayments' => DB::table('repayments')->count(),
            'import_rows' => DB::table('csv_import_rows')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];
    }
}
