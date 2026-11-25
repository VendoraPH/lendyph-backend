<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\PortalUser;
use App\Models\User;
use App\Services\CsvImport\CsvImportProcessor;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLookupRequest;
use App\Services\Portal\Pdf\PortalPdf;
use App\Services\Portal\Pdf\PortalStatements;
use App\Services\RepaymentService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Portal\PortalTestHelpers;
use Tests\TestCase;
use Tests\Traits\BuildsCsvImports;
use Tests\Traits\SetupLendyPH;

/**
 * A loan migrated in by the CSV importer, as the screens show it (finish run,
 * round 5): its Total Paid, and the file's own Loan No.
 *
 * The file says what was paid before the import: Loan Amount less Loan Balance
 * (principal) and Interest Amount less Interest Balance (interest). The
 * importer lays it on the schedule's paid columns and records no payment, so
 * Total Paid read ₱0.00 beside Principal Paid ₱20,000.00. Every reader of
 * Total Paid now adds it, so Total Paid agrees with Principal Paid and
 * Interest Paid, and a loan that was not imported reads as before.
 *
 * The fixture is BuildsCsvImports' own loan row: ₱60,000.00 lent with
 * ₱40,000.00 left, and ₱10,800.00 interest with ₱7,200.00 left, so ₱20,000.00
 * principal and ₱3,600.00 interest were paid before the import.
 */
class ImportedLoanDisplayTest extends TestCase
{
    use BuildsCsvImports, PortalTestHelpers, SetupLendyPH;

    private const PAID_BEFORE_IMPORT = 23600.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedForImport();
        $this->actingAs($this->admin);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_total_paid_on_an_imported_loan_includes_what_the_file_says_was_paid_before_the_import(): void
    {
        $loan = $this->importLoan();

        $summary = $this->staffGet("/api/loans/{$loan->id}/summary")->json('data');

        $this->assertSame(20000.0, (float) $summary['principal_paid']);
        $this->assertSame(3600.0, (float) $summary['interest_paid']);
        $this->assertSame(self::PAID_BEFORE_IMPORT, (float) $summary['paid_before_import']);
        $this->assertSame(self::PAID_BEFORE_IMPORT, (float) $summary['total_repaid'], 'Total Paid agrees with Principal Paid and Interest Paid.');
    }

    public function test_a_payment_after_the_import_adds_to_it_on_every_reader(): void
    {
        $loan = $this->importLoan();
        $payment = $this->pay($loan, 2000)->json('data');
        $expected = self::PAID_BEFORE_IMPORT + 2000;

        // The loan page (GET /loans/{id}/summary): Total Paid is every part paid.
        $summary = $this->staffGet("/api/loans/{$loan->id}/summary")->json('data');
        $this->assertSame($expected, (float) $summary['total_repaid']);
        $this->assertSame(self::PAID_BEFORE_IMPORT, (float) $summary['paid_before_import']);
        $this->assertSame(
            $this->centavos($summary['total_repaid']),
            $this->centavos($summary['principal_paid']) + $this->centavos($summary['interest_paid']) + $this->centavos($summary['penalty_paid']),
        );

        // The Statement of Account: Total Paid, its parts adding up to it, and
        // the Receipt Amount column's own total (the receipts here).
        $statement = $this->staffGet("/api/reports/statement-of-account/{$loan->id}")->assertOk()->json('data');
        $this->assertSame($expected, (float) $statement['summary']['total_paid']);
        $this->assertSame(self::PAID_BEFORE_IMPORT, (float) $statement['summary']['paid_before_import']);
        $this->assertSame((float) $summary['principal_paid'], (float) $statement['summary']['principal_paid']);
        $this->assertSame((float) $summary['interest_paid'], (float) $statement['summary']['interest_paid']);
        $this->assertSame(
            $this->centavos($statement['summary']['total_paid']),
            array_sum(array_map(fn (string $part): int => $this->centavos($statement['summary'][$part]), [
                'principal_paid', 'interest_paid', 'penalty_paid', 'insurance_paid', 'build_up_paid', 'overpayment_paid',
            ])),
        );
        $this->assertSame(2000.0, (float) $statement['transaction_totals']['amount_paid']);

        // The member page (GET /borrowers/{id}/loan-summary).
        $member = $this->staffGet("/api/borrowers/{$loan->borrower_id}/loan-summary")->assertOk()->json('data');
        $this->assertSame($expected, (float) $member['total_paid']);
        $this->assertSame(self::PAID_BEFORE_IMPORT, (float) $member['paid_before_import']);

        // The Subsidiary Ledger and the Member Ledger Card, every date.
        $ledger = $this->staffGet("/api/reports/subsidiary-ledger/{$loan->borrower_id}")->assertOk()->json('data');
        $this->assertSame($expected, (float) $ledger['loans'][0]['total_paid']);
        $this->assertSame(self::PAID_BEFORE_IMPORT, (float) $ledger['loans'][0]['paid_before_import']);
        $this->assertSame($expected, (float) $ledger['loans_totals']['total_paid']);
        $this->assertSame($expected, (float) $ledger['totals']['total_paid']);
        $this->assertSame(self::PAID_BEFORE_IMPORT, (float) $ledger['totals']['paid_before_import']);

        // The Member Portal: the loan's Total Paid and its statement.
        $member = PortalUser::factory()->create(['borrower_id' => $loan->borrower_id])->refresh();
        $this->enablePortal();
        $detail = $this->portalJson('GET', "/api/portal/loans/{$loan->id}", [], $this->portalToken($member))->assertOk()->json('data');
        $this->assertSame('25600.00', $detail['total_repaid']);
        $this->assertSame('23600.00', $detail['paid_before_import']);

        $html = app(PortalPdf::class)->html(app(PortalStatements::class)->loan($loan->fresh()));
        $this->assertStringContainsString('₱25,600.00', $html);
        $this->assertStringContainsString('Paid before the loan was imported', $html);

        // Voiding the payment takes back only the payment.
        $this->asStaff()->patchJson("/api/repayments/{$payment['id']}/void", ['void_reason' => 'Wrong amount entered'])->assertOk();
        $this->assertSame(self::PAID_BEFORE_IMPORT, (float) $this->staffGet("/api/loans/{$loan->id}/summary")->json('data.total_repaid'));
    }

    public function test_the_ledger_row_and_total_paid_state_one_figure(): void
    {
        $loan = $this->importLoan();
        $this->pay($loan, 2000);

        $row = collect($this->staffGet("/api/loans/{$loan->id}/ledger")->assertOk()->json('data.rows'))
            ->firstWhere('description', 'Paid before the loan was imported');

        $this->assertNotNull($row);
        $this->assertSame(-20000.0, (float) $row['principal']);
        $this->assertSame(-3600.0, (float) $row['interest']);
        $this->assertSame(
            self::PAID_BEFORE_IMPORT,
            (float) $this->staffGet("/api/loans/{$loan->id}/summary")->json('data.paid_before_import'),
        );
    }

    public function test_the_subsidiary_ledger_counts_it_on_the_release_date_as_the_loan_ledger_dates_it(): void
    {
        $loan = $this->importLoan();
        $this->pay($loan, 2000);
        $released = $loan->released_at->toDateString();
        $today = now()->toDateString();

        $thisMonth = $this->staffGet("/api/reports/subsidiary-ledger/{$loan->borrower_id}?date_from={$today}&date_to={$today}")->json('data');
        $this->assertSame(2000.0, (float) $thisMonth['loans'][0]['total_paid'], 'Paid before the import is not money received today.');
        $this->assertSame(0.0, (float) $thisMonth['loans'][0]['paid_before_import']);
        $this->assertSame(2000.0, (float) $thisMonth['totals']['total_paid']);

        $fromRelease = $this->staffGet("/api/reports/subsidiary-ledger/{$loan->borrower_id}?date_from={$released}&date_to={$today}")->json('data');
        $this->assertSame(self::PAID_BEFORE_IMPORT + 2000, (float) $fromRelease['loans'][0]['total_paid']);
    }

    public function test_a_loan_that_was_not_imported_is_unchanged(): void
    {
        $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(2)->toDateString()]);
        $this->pay($loan, 2000);

        $summary = $this->staffGet("/api/loans/{$loan->id}/summary")->json('data');
        $this->assertSame(2000.0, (float) $summary['total_repaid']);
        $this->assertSame(0.0, (float) $summary['paid_before_import']);

        $statement = $this->staffGet("/api/reports/statement-of-account/{$loan->id}")->json('data');
        $this->assertSame(2000.0, (float) $statement['summary']['total_paid']);
        $this->assertSame(0.0, (float) $statement['summary']['paid_before_import']);
        $this->assertSame(2000.0, (float) $statement['transaction_totals']['amount_paid']);

        $member = $this->staffGet("/api/borrowers/{$loan->borrower_id}/loan-summary")->json('data');
        $this->assertSame(2000.0, (float) $member['total_paid']);
        $this->assertSame(0.0, (float) $member['paid_before_import']);

        $ledger = $this->staffGet("/api/reports/subsidiary-ledger/{$loan->borrower_id}")->json('data');
        $this->assertSame(2000.0, (float) $ledger['loans'][0]['total_paid']);
        $this->assertSame(0.0, (float) $ledger['totals']['paid_before_import']);
    }

    public function test_linda_never_says_an_imported_loan_has_had_no_payment(): void
    {
        $loan = $this->importLoan();

        $reply = app(LindaAnswerer::class)->lookup(
            $this->admin,
            new LindaLookupRequest(LindaLookupRequest::LOAN, LindaIntent::LoanDetails, $loan->application_number),
        );

        $this->assertSame('answered', $reply->outcome);
        $this->assertStringNotContainsString('No payment has been made yet', $reply->answer);
        $this->assertStringContainsString('No payment has been recorded since the import; ₱23,600.00 was paid before it.', $reply->answer);
    }

    public function test_the_loans_search_finds_the_files_loan_no_as_it_finds_the_other_numbers_in_the_users_branches(): void
    {
        $loans = $this->importLoans(['L-2019-0042' => [], 'L-2019-0042-FAR' => []]);
        $far = Branch::factory()->create();
        DB::table('loans')->where('id', $loans['L-2019-0042-FAR']->id)->update(['branch_id' => $far->id]);
        DB::table('borrowers')->where('id', $loans['L-2019-0042-FAR']->borrower_id)->update(['branch_id' => $far->id]);
        $this->createReleasedLoan();

        // A loan officer of the Main Branch only (no branches:view_all).
        $officer = User::factory()->inBranches($this->branch)->create();
        $officer->assignRole('loan_officer');
        $mine = $loans['L-2019-0042']->id;

        foreach (['L-2019-0042', '2019-0042', 'l-2019'] as $term) {
            $this->assertSame([$mine], $this->searchIds($officer, $term), $term);
            $this->assertSame([$mine], $this->searchIds($officer, $term, '&view=summary'), "{$term}, summary rows");
        }

        // Another branch's loan is never found by its file number.
        $this->assertSame([], $this->searchIds($officer, 'L-2019-0042-FAR'));
        $this->assertSame([$loans['L-2019-0042-FAR']->id], $this->searchIds($this->admin, 'L-2019-0042-FAR'));

        // LIKE's own wildcards match only themselves, as on the other numbers.
        $this->assertSame([], $this->searchIds($officer, 'L-2019_0042'));
        $this->assertSame([], $this->searchIds($officer, 'L-2019%0042'));

        $this->staffGet("/api/loans/{$mine}")->assertOk()->assertJsonPath('data.external_loan_no', 'L-2019-0042');
    }

    public function test_the_part_paid_before_the_import_holds_through_a_waiver_a_balance_adjustment_and_a_restructure(): void
    {
        $loan = $this->importLoan();

        // Period 6 fell due after the import's as-of date, so it is charged a
        // late penalty, which the waiver then forgives.
        app(RepaymentService::class)->applyPenalties($loan->fresh(), now());
        $this->assertGreaterThan(0, (float) $loan->amortizationSchedules()->sum('penalty_amount'));

        $this->applyAdjustment($loan, ['adjustment_type' => 'penalty_waiver', 'new_values' => ['waive_all' => true]]);
        $this->assertPaidBeforeImport($loan, self::PAID_BEFORE_IMPORT, 'after the penalty waiver');

        $this->applyAdjustment($loan, [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['target_principal_balance' => 39000, 'reason' => 'Agreed settlement'],
        ]);
        $this->assertPaidBeforeImport($loan, self::PAID_BEFORE_IMPORT, 'after the balance adjustment');

        $this->applyAdjustment($loan, [
            'adjustment_type' => 'restructure',
            'new_values' => ['term' => 12],
            'description' => 'Reschedule the outstanding balance',
        ]);
        $this->assertPaidBeforeImport($loan, self::PAID_BEFORE_IMPORT, 'after the restructure');
    }

    public function test_the_part_paid_before_the_import_holds_through_an_extension(): void
    {
        // Only a loan agreed for one month extends, which the six-month fixture
        // isn't, so this one-month imported loan carries its own file figure:
        // ₱10,000.00 lent with ₱6,000.00 left, and ₱300.00 interest still owed.
        $loan = $this->importLoan('L-2019-0050', [
            'loan_amount' => '10,000.00',
            'loan_balance' => '6,000.00',
            'interest_amount' => '300.00',
            'interest_balance' => '300.00',
            'term_in_months' => '1',
            'date_released' => '2026-06-15',
            'maturity_date' => '2026-07-15',
        ]);
        $this->assertTrue($loan->isOneMonthTerm());
        $this->assertPaidBeforeImport($loan, 4000.0, 'as imported');

        $this->asStaff()->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();

        $this->assertPaidBeforeImport($loan, 4000.0, 'after the extension');
    }

    /**
     * One member and one loan, through the real staging and import pass.
     *
     * @param  array<string, string>  $overrides  the loan row's cells
     */
    private function importLoan(string $loanNo = 'L-2019-0042', array $overrides = []): Loan
    {
        return $this->importLoans([$loanNo => $overrides])[$loanNo];
    }

    /**
     * A member per loan, through the real staging and import pass.
     *
     * @param  array<string, array<string, string>>  $loans  each loan row's cells, by the file's Loan No.
     * @return array<string, Loan> by the file's Loan No.
     */
    private function importLoans(array $loans): array
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
        $customers = [];
        $rows = [];

        foreach (array_keys($loans) as $index => $loanNo) {
            $account = sprintf('A-%03d', $index + 1);
            $customers[] = $this->customerRow($account);
            $rows[] = $this->loanRow($account, $loanNo, $loans[$loanNo]);
        }

        $this->makeFile($run, 'customers', $customers);
        $this->makeFile($run, 'loans', $rows);

        $processor = new CsvImportProcessor;

        for ($tick = 0; $tick < 200 && ! $processor->advance($run)->idle; $tick++) {
            $run->refresh();
        }

        $this->assertSame('completed', $run->fresh()->phase);

        return Loan::query()->whereIn('external_loan_no', array_keys($loans))->get()->keyBy('external_loan_no')->all();
    }

    /** An adjustment requested, approved and applied through the API. */
    private function applyAdjustment(Loan $loan, array $request): void
    {
        $id = $this->asStaff()->postJson("/api/loans/{$loan->id}/adjustments", $request)->assertCreated()->json('data.id');
        $this->asStaff()->patchJson("/api/loan-adjustments/{$id}/approve", ['remarks' => 'OK'])->assertOk();
        $this->asStaff()->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk()->assertJsonPath('data.status', 'applied');
    }

    /** Total Paid's part paid before the import, and Total Paid itself with no payment here. */
    private function assertPaidBeforeImport(Loan $loan, float $expected, string $when): void
    {
        $summary = $this->staffGet("/api/loans/{$loan->id}/summary")->assertOk()->json('data');

        $this->assertSame($expected, (float) $summary['paid_before_import'], $when);
        $this->assertSame($expected, (float) $summary['total_repaid'], $when);
    }

    /** A cash payment recorded by staff through the API, today. */
    private function pay(Loan $loan, float $amount): TestResponse
    {
        $response = $this->asStaff()->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $amount,
            'method' => 'cash',
        ]);
        $this->assertSame(201, $response->status(), (string) $response->getContent());

        return $response;
    }

    private function staffGet(string $uri): TestResponse
    {
        return $this->asStaff()->getJson($uri);
    }

    /** The super admin again, after a Member Portal request left its token behind. */
    private function asStaff(): static
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        return $this->actingAs($this->admin, 'web');
    }

    /** @return list<int> the ids GET /loans finds for the term, as this user */
    private function searchIds(User $user, string $term, string $query = ''): array
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];

        return array_column($this->actingAs($user, 'web')->getJson('/api/loans?search='.urlencode($term).$query)->assertOk()->json('data'), 'id');
    }

    private function centavos(mixed $pesos): int
    {
        return (int) round((float) $pesos * 100);
    }
}
