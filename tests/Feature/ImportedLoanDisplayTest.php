<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\PortalUser;
use App\Services\CsvImport\CsvImportProcessor;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLookupRequest;
use App\Services\Portal\Pdf\PortalPdf;
use App\Services\Portal\Pdf\PortalStatements;
use Illuminate\Routing\Middleware\ThrottleRequests;
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

    public function test_the_loans_search_finds_the_files_loan_no_as_it_finds_the_other_numbers(): void
    {
        $loan = $this->importLoan();
        $this->createReleasedLoan();

        foreach (['L-2019-0042', '2019-0042', 'l-2019'] as $term) {
            $this->assertSame([$loan->id], $this->searchIds($term), $term);
            $this->assertSame([$loan->id], $this->searchIds($term, '&view=summary'), "{$term}, summary rows");
        }

        // LIKE's own wildcards match only themselves, as on the other numbers.
        $this->assertSame([], $this->searchIds('L-2019_0042'));
        $this->assertSame([], $this->searchIds('L-2019%0042'));

        $this->staffGet("/api/loans/{$loan->id}")->assertOk()->assertJsonPath('data.external_loan_no', 'L-2019-0042');
    }

    /**
     * One member and one loan, through the real staging and import pass.
     */
    private function importLoan(): Loan
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
        $this->makeFile($run, 'customers', [$this->customerRow('A-001')]);
        $this->makeFile($run, 'loans', [$this->loanRow('A-001', 'L-2019-0042')]);

        $processor = new CsvImportProcessor;

        for ($tick = 0; $tick < 200 && ! $processor->advance($run)->idle; $tick++) {
            $run->refresh();
        }

        $this->assertSame('completed', $run->fresh()->phase);

        return Loan::where('external_loan_no', 'L-2019-0042')->firstOrFail();
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

    /** @return list<int> the ids GET /loans finds for the term */
    private function searchIds(string $term, string $query = ''): array
    {
        return array_column($this->staffGet('/api/loans?search='.urlencode($term).$query)->assertOk()->json('data'), 'id');
    }

    private function centavos(mixed $pesos): int
    {
        return (int) round((float) $pesos * 100);
    }
}
