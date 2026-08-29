<?php

namespace Tests\Feature\CsvImport;

use App\Models\CsvImportFile;
use App\Models\CsvImportRun;
use App\Models\LoanProduct;
use App\Services\CsvImport\ErrorReportBuilder;
use App\Services\CsvImport\ProductMappingResolver;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use Tests\Traits\StagesCsvImportRuns;

/**
 * Out-of-product-bounds loans land silently, and the report has to say which.
 *
 * The importer bypasses LoanService::createLoan(), so its min/max amount, term
 * and rate guards never fire. That is deliberate and stays — a migration has to
 * carry historical loans today's single ₱1,000–75,000 product would reject, and
 * enforcing would make the migration impossible rather than safe. What was
 * missing is the trace: ProductMappingResolver forecasts the count BEFORE the
 * run, and afterwards nothing said which rows it turned out to be.
 *
 * The importer stamps `result_category` on the row it wrote; this is the reader
 * half, and it is a WARNING because the loan is on the books and correct as the
 * cooperative recorded it.
 */
class OutOfProductBoundsCategoryTest extends TestCase
{
    use StagesCsvImportRuns;

    private CsvImportRun $run;

    private CsvImportFile $loans;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAndLoginAsImportAdmin();

        $this->run = $this->makeImportRun(['phase' => 'completed', 'finished_at' => now()->subDays(45)]);
        $this->loans = $this->makeImportFile($this->run, 'loans', ['original_filename' => 'binhs-loans-2026.csv']);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function stageBreach(int $line, array $options = []): void
    {
        $this->stageRow($this->loans, $line, $this->loanValues([
            'loan_no' => "L-{$line}",
            'loan_amount' => 12000000,
        ]), array_merge([
            'result' => 'imported',
            'result_category' => ErrorReportBuilder::CATEGORY_OUT_OF_PRODUCT_BOUNDS,
            'result_message' => 'Loan amount 120,000.00 is above the product maximum of 75,000.00.',
        ], $options));
    }

    private function stats(string $query = ''): array
    {
        return $this->getJson("/api/imports/{$this->run->id}/errors{$query}")->assertOk()->json('meta.stats');
    }

    public function test_an_imported_row_outside_its_product_bounds_is_reported_as_a_warning(): void
    {
        $this->stageBreach(2);
        $this->stageBreach(3);

        $stats = $this->stats();

        $this->assertSame(2, $stats['by_severity']['warning']);
        $this->assertSame(0, $stats['by_severity']['error']);

        $group = collect($stats['by_category'])
            ->firstWhere('category', ErrorReportBuilder::CATEGORY_OUT_OF_PRODUCT_BOUNDS);

        $this->assertNotNull($group, 'The breach must surface as its own reviewable group.');
        $this->assertSame(2, $group['count']);
        $this->assertSame('warning', $group['severity']);
    }

    public function test_the_row_appears_in_the_paginated_report_and_the_csv(): void
    {
        $this->stageBreach(2);

        $issue = $this->getJson("/api/imports/{$this->run->id}/errors")->assertOk()->json('data.0');

        $this->assertSame(ErrorReportBuilder::CATEGORY_OUT_OF_PRODUCT_BOUNDS, $issue['category']);
        $this->assertSame('warning', $issue['severity']);
        $this->assertSame(2, $issue['row_number'], 'The physical line, which is what the admin sees in their gutter.');
        $this->assertSame('L-2', $issue['loan_no']);

        $csv = $this->get("/api/imports/{$this->run->id}/errors.csv")->assertOk()->streamedContent();

        $this->assertStringContainsString(ErrorReportBuilder::CATEGORY_OUT_OF_PRODUCT_BOUNDS, $csv);
        $this->assertStringContainsString('is above the product maximum', $csv);
    }

    public function test_it_is_excluded_by_the_error_only_filter(): void
    {
        $this->stageBreach(2);

        $this->assertSame(0, $this->stats('?severity=error')['total_issues']);
        $this->assertSame(1, $this->stats('?severity=warning')['total_issues']);
    }

    public function test_a_failed_row_carrying_the_category_is_not_counted_twice(): void
    {
        /*
         * `result_category` is one column with one value, and a failed row
         * already emits an ERROR line from it. Emitting the warning as well
         * would report one row twice under one category and double the number an
         * operator is asked to review.
         */
        $this->stageBreach(2, ['result' => 'failed']);

        $stats = $this->stats();

        $this->assertSame(1, $stats['total_issues']);
        $this->assertSame(1, $stats['rows_reported']);
        $this->assertSame(1, $stats['by_severity']['error']);
        $this->assertSame(0, $stats['by_severity']['warning']);
    }

    public function test_a_skipped_row_carrying_the_category_is_not_counted_twice(): void
    {
        $this->stageBreach(2, ['result' => 'skipped']);

        $stats = $this->stats();

        $this->assertSame(1, $stats['total_issues']);
        $this->assertSame(1, $stats['by_severity']['warning']);
    }

    public function test_the_reviewable_list_survives_redaction(): void
    {
        $this->stageBreach(2);

        Artisan::call('imports:redact-rows');

        $stats = $this->stats();
        $group = collect($stats['by_category'])
            ->firstWhere('category', ErrorReportBuilder::CATEGORY_OUT_OF_PRODUCT_BOUNDS);

        /*
         * `result_category` is one of the columns redaction keeps, so the count
         * an operator reviews is still there months later. Only the sentence
         * quoting the amount reverts to the generic wording.
         */
        $this->assertSame(1, $group['count']);
        $this->assertSame(
            'This loan was imported outside the limits configured on its loan product.',
            $group['label'],
        );
    }

    // ── the writer half: the check the importer calls ────────────────────

    private function regularProduct(): LoanProduct
    {
        // The target cooperative's single product, verbatim:
        // Regular Loan | diminishing | 30 monthly | P1,000-75,000 | 2-3%.
        return LoanProduct::factory()->create([
            'name' => 'Regular Loan',
            'interest_method' => 'diminishing',
            'interest_rate' => 3.0,
            'min_interest_rate' => 2.0,
            'term' => 30,
            'min_term' => 1,
            'max_term' => 30,
            'frequency' => 'monthly',
            'min_amount' => 1000,
            'max_amount' => 75000,
        ]);
    }

    public function test_the_bounds_check_names_every_broken_bound(): void
    {
        $product = $this->regularProduct();

        // 120,000 over 36 months at 4% breaks the maximum of all three.
        $this->assertSame(
            ['amount_above_max', 'term_above_max', 'rate_above_max'],
            ProductMappingResolver::boundsBreaches($product, 12000000, 36, 4.0),
        );

        $this->assertSame(
            ['amount_below_min'],
            ProductMappingResolver::boundsBreaches($product, 50000, 12, 3.0),
        );

        $this->assertSame([], ProductMappingResolver::boundsBreaches($product, 5000000, 12, 3.0));
    }

    public function test_the_bounds_check_reads_the_staged_string_form_identically(): void
    {
        /*
         * The mapping forecast reads these back out of JSON, where they are
         * strings; the importer holds them typed. Both callers must get the same
         * answer or the screen would promise one number and the report list
         * another.
         */
        $product = $this->regularProduct();

        $this->assertSame(
            ProductMappingResolver::boundsBreaches($product, 12000000, 36, 4.0),
            ProductMappingResolver::boundsBreaches($product, '12000000', '36', '4'),
        );
    }

    public function test_a_row_too_malformed_to_compare_reports_no_breach(): void
    {
        $product = $this->regularProduct();

        /*
         * Not "in bounds" — uncomparable. The row has its own error line already,
         * and counting it as a breach would pad the list an operator reviews with
         * rows they cannot act on from here.
         */
        $this->assertSame([], ProductMappingResolver::boundsBreaches($product, null, null, null));
        $this->assertSame([], ProductMappingResolver::boundsBreaches($product, 'n/a', 'twelve', 'three'));
    }
}
