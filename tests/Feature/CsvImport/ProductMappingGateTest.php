<?php

namespace Tests\Feature\CsvImport;

use App\Models\AuditLog;
use App\Models\CsvImportRun;
use App\Models\LoanProduct;
use App\Services\CsvImport\ProductMappingResolver;
use Tests\TestCase;
use Tests\Traits\StagesCsvImportRuns;

/**
 * The gate that has to close before a single loan is written.
 *
 * `loans.loan_product_id` is NOT NULL, `loan_products.name` is not unique, and
 * nothing in this codebase resolves a product by name — so an unmapped product
 * string is not an inconvenience, it is a row that cannot exist. These tests pin
 * the three ways that gate can be quietly left open: proceeding with a string
 * unmapped, accepting an id that is not a product, and inventing a product from
 * a bare name.
 */
class ProductMappingGateTest extends TestCase
{
    use StagesCsvImportRuns;

    private CsvImportRun $run;

    private LoanProduct $regular;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAndLoginAsImportAdmin();

        /**
         * The target cooperative's single product, verbatim:
         * Regular Loan | diminishing | 30 monthly | ₱1,000–75,000.
         */
        $this->regular = LoanProduct::factory()->create([
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

        $this->run = $this->makeImportRun();
    }

    private function stageLoans(): void
    {
        $file = $this->makeImportFile($this->run, 'loans');

        // Exactly the product name, differently cased and spaced. One string.
        $this->stageRow($file, 2, $this->loanValues(['loan_no' => 'L-1', 'loan_product' => 'Regular Loan']));
        $this->stageRow($file, 3, $this->loanValues(['loan_no' => 'L-2', 'loan_product' => '  REGULAR   loan ']));

        // A string this deployment has no product for at all.
        $this->stageRow($file, 4, $this->loanValues(['loan_no' => 'L-3', 'loan_product' => 'Emergency']));
    }

    public function test_it_lists_every_distinct_product_string_with_its_loan_count(): void
    {
        $this->stageLoans();

        $response = $this->getJson("/api/imports/{$this->run->id}/product-mapping")->assertOk();

        $products = collect($response->json('data.csv_products'))->keyBy('csv_value');

        // "  REGULAR   loan " is trimmed to the staged value, not folded into
        // "Regular Loan" — they are different strings in the file and the admin
        // maps each. Only the SUGGESTION folds case and whitespace.
        $this->assertEqualsCanonicalizing(
            ['Regular Loan', 'REGULAR   loan', 'Emergency'],
            $products->keys()->all(),
            'Every distinct staged product string must be listed, trimmed but not merged.',
        );

        $this->assertSame(1, $products['Regular Loan']['loan_count']);
        $this->assertSame(1, $products['Emergency']['loan_count']);
        $this->assertTrue($products['Regular Loan']['blocking']);
    }

    public function test_a_suggestion_is_an_exact_case_and_whitespace_insensitive_match_and_nothing_else(): void
    {
        $this->stageLoans();

        $products = collect($this->getJson("/api/imports/{$this->run->id}/product-mapping")->json('data.csv_products'))
            ->keyBy('csv_value');

        $this->assertSame($this->regular->id, $products['Regular Loan']['suggested_loan_product_id']);
        $this->assertSame('exact_name_match', $products['Regular Loan']['suggestion_reason']);

        // Case folded and whitespace collapsed — still the same product name.
        $this->assertSame($this->regular->id, $products['REGULAR   loan']['suggested_loan_product_id']);

        // "Emergency" is not "Regular Loan". No fuzzy match, no suggestion —
        // quietly landing a cohort on the wrong product re-prices every loan in
        // it and the result looks completely ordinary.
        $this->assertNull($products['Emergency']['suggested_loan_product_id']);
        $this->assertSame('no_match', $products['Emergency']['suggestion_reason']);
    }

    public function test_a_name_matching_two_products_yields_no_suggestion(): void
    {
        $twin = LoanProduct::factory()->create(['name' => 'regular loan', 'interest_method' => 'straight']);

        $this->stageLoans();

        $entry = collect($this->getJson("/api/imports/{$this->run->id}/product-mapping")->json('data.csv_products'))
            ->firstWhere('csv_value', 'Regular Loan');

        $this->assertNull($entry['suggested_loan_product_id'], 'An ambiguous name must not be resolved for the admin.');
        $this->assertSame('ambiguous_name', $entry['suggestion_reason']);
        $this->assertEqualsCanonicalizing([$this->regular->id, $twin->id], $entry['suggestion_candidate_ids']);
    }

    public function test_it_refuses_to_proceed_while_any_string_is_unmapped(): void
    {
        $this->stageLoans();

        $response = $this->putJson("/api/imports/{$this->run->id}/product-mapping", [
            'Regular Loan' => $this->regular->id,
            'REGULAR   loan' => $this->regular->id,
            // "Emergency" deliberately absent.
        ])->assertStatus(422);

        $this->assertSame(['Emergency'], $response->json('errors.unmapped'));
        $this->assertNull($this->run->fresh()->product_mapping, 'A rejected mapping must not be stored.');
    }

    public function test_a_blank_product_cell_is_surfaced_and_must_be_mapped(): void
    {
        $file = $this->makeImportFile($this->run, 'loans');
        $this->stageRow($file, 2, $this->loanValues(['loan_product' => null]));

        $entry = collect($this->getJson("/api/imports/{$this->run->id}/product-mapping")->json('data.csv_products'))
            ->firstWhere('csv_value', '');

        $this->assertNotNull($entry, 'A blank Loan Product cell still needs a product — the FK is NOT NULL.');
        $this->assertTrue($entry['is_blank']);
        $this->assertTrue($entry['blocking']);

        $this->putJson("/api/imports/{$this->run->id}/product-mapping", [])->assertStatus(422);

        $this->putJson("/api/imports/{$this->run->id}/product-mapping", ['' => $this->regular->id])->assertOk();

        $this->assertSame(['' => $this->regular->id], $this->run->fresh()->product_mapping);
    }

    public function test_a_nonexistent_product_id_is_rejected(): void
    {
        $this->stageLoans();

        $missingId = LoanProduct::max('id') + 500;

        $response = $this->putJson("/api/imports/{$this->run->id}/product-mapping", [
            'Regular Loan' => $missingId,
            'REGULAR   loan' => $this->regular->id,
            'Emergency' => $this->regular->id,
        ])->assertStatus(422);

        $this->assertNotEmpty(
            preg_grep('/^loan_product_ids/', array_keys($response->json('errors'))),
            'An id that is not a loan product must fail exists:loan_products,id.',
        );

        $this->assertNull($this->run->fresh()->product_mapping);
    }

    public function test_it_never_creates_a_loan_product_from_a_name(): void
    {
        $this->stageLoans();

        $before = LoanProduct::count();

        // The tempting shortcut, twice: an unmapped name, then a name that
        // matches nothing being mapped to the one real product.
        $this->putJson("/api/imports/{$this->run->id}/product-mapping", [
            'Regular Loan' => $this->regular->id,
        ])->assertStatus(422);

        $this->assertSame($before, LoanProduct::count());

        $this->putJson("/api/imports/{$this->run->id}/product-mapping", [
            'Regular Loan' => $this->regular->id,
            'REGULAR   loan' => $this->regular->id,
            'Emergency' => $this->regular->id,
        ])->assertOk();

        // A bare name carries no rate, method, term, fee, penalty or grace
        // period. Creating one would invent all six, and every loan in the
        // cohort would be carried on the invention.
        $this->assertSame($before, LoanProduct::count(), 'No product may be created from a CSV string.');
        $this->assertNull(LoanProduct::where('name', 'Emergency')->first());
    }

    public function test_the_response_counts_interest_method_disagreement_and_out_of_bounds_loans(): void
    {
        $file = $this->makeImportFile($this->run, 'loans');

        // Straight against a diminishing product: the importer writes the CSV's
        // method (it is $fillable and it is the legacy contract), but
        // createLoan() takes the method from the product, so this loan
        // permanently disagrees with its own product — and
        // DisclosureService::generateDisclosure() prints both in one block.
        $this->stageRow($file, 2, $this->loanValues(['loan_no' => 'L-1', 'interest_type' => 'straight']));
        $this->stageRow($file, 3, $this->loanValues(['loan_no' => 'L-2', 'interest_type' => 'straight']));
        $this->stageRow($file, 4, $this->loanValues(['loan_no' => 'L-3', 'interest_type' => 'diminishing']));

        // ₱120,000 over 36 months at 5% against a ₱1,000–75,000 / 30-month / 3%
        // product. The importer bypasses LoanService::createLoan(), so none of
        // its guards fire and nothing else will stop this being written.
        $this->stageRow($file, 5, $this->loanValues([
            'loan_no' => 'L-4',
            'interest_type' => 'diminishing',
            'loan_amount' => 12000000,
            'term_in_months' => 36,
            'interest_rate' => '5',
        ]));

        $entry = collect($this->getJson("/api/imports/{$this->run->id}/product-mapping")->json('data.csv_products'))
            ->firstWhere('csv_value', 'Regular Loan');

        $this->assertSame($this->regular->id, $entry['compatibility']['checked_against_loan_product_id']);
        $this->assertSame('diminishing', $entry['compatibility']['interest_method']['product_interest_method']);
        $this->assertSame(2, $entry['compatibility']['interest_method']['disagreeing_rows']);
        $this->assertSame(['straight' => 2, 'diminishing' => 2], $entry['compatibility']['interest_method']['csv_interest_types']);

        $bounds = $entry['compatibility']['out_of_bounds'];
        $this->assertSame(1, $bounds['amount_above_max']);
        $this->assertSame(1, $bounds['term_above_max']);
        $this->assertSame(1, $bounds['rate_above_max']);
        // One row breached three bounds; it is one loan, counted once.
        $this->assertSame(1, $bounds['rows']);

        $totals = $this->getJson("/api/imports/{$this->run->id}/product-mapping")->json('data.totals');
        $this->assertSame(2, $totals['rows_with_interest_method_disagreement']);
        $this->assertSame(1, $totals['rows_outside_product_bounds']);
    }

    public function test_compatibility_counts_only_rows_that_can_actually_be_imported(): void
    {
        $file = $this->makeImportFile($this->run, 'loans');

        // Importable, and disagreeing with the product's method.
        $this->stageRow($file, 2, $this->loanValues(['loan_no' => 'L-1', 'interest_type' => 'straight']));

        // Staging already rejected this one, so it will never be written.
        // Counting it towards "N loans will disagree with their product" would
        // overstate the warning by exactly the rows the admin is about to fix.
        $this->stageRow($file, 3, $this->loanValues([
            'loan_no' => 'L-2',
            'interest_type' => 'straight',
            'loan_amount' => 99000000,
        ]), [
            'errors' => [['maturity_date', 'date_invalid', 'Not a date.']],
        ]);

        $entry = collect($this->getJson("/api/imports/{$this->run->id}/product-mapping")->json('data.csv_products'))
            ->firstWhere('csv_value', 'Regular Loan');

        $this->assertSame(2, $entry['loan_count']);
        $this->assertSame(1, $entry['compatibility']['rows_evaluated']);
        $this->assertSame(1, $entry['compatibility']['rows_not_importable']);
        $this->assertSame(1, $entry['compatibility']['interest_method']['disagreeing_rows']);
        $this->assertSame(0, $entry['compatibility']['out_of_bounds']['amount_above_max']);
    }

    public function test_out_of_bounds_loans_are_warnings_and_never_block_the_mapping(): void
    {
        $file = $this->makeImportFile($this->run, 'loans');

        // Legacy data routinely sits outside today's configuration. With one
        // ₱1,000–75,000 product, enforcing it would make the migration
        // impossible.
        $this->stageRow($file, 2, $this->loanValues(['loan_amount' => 50000000, 'term_in_months' => 60]));

        $response = $this->putJson("/api/imports/{$this->run->id}/product-mapping", [
            'Regular Loan' => $this->regular->id,
        ])->assertOk();

        $this->assertSame(['Regular Loan' => $this->regular->id], $this->run->fresh()->product_mapping);
        $this->assertStringContainsString('outside', $response->json('message'));
    }

    public function test_confirming_the_mapping_writes_an_audit_record(): void
    {
        $this->stageLoans();

        $this->putJson("/api/imports/{$this->run->id}/product-mapping", [
            'Regular Loan' => $this->regular->id,
            'REGULAR   loan' => $this->regular->id,
            'Emergency' => $this->regular->id,
        ])->assertOk();

        $log = AuditLog::where('action', 'import_product_mapping_confirmed')->latest('id')->first();

        $this->assertNotNull($log, 'Which product each legacy cohort landed on is an accountability record.');
        $this->assertSame($this->importAdmin->id, $log->user_id);
        $this->assertSame(CsvImportRun::class, $log->auditable_type);
        $this->assertSame($this->run->id, $log->auditable_id);
        $this->assertSame($this->regular->id, $log->new_values['product_mapping']['Emergency']);
        $this->assertStringContainsString('Regular Loan', $log->description);
    }

    public function test_a_finished_run_cannot_have_its_mapping_rewritten(): void
    {
        $this->stageLoans();
        $this->run->update(['phase' => 'completed']);

        $this->putJson("/api/imports/{$this->run->id}/product-mapping", [
            'Regular Loan' => $this->regular->id,
            'REGULAR   loan' => $this->regular->id,
            'Emergency' => $this->regular->id,
        ])->assertStatus(409);

        $this->assertNull($this->run->fresh()->product_mapping, 'The mapping is the record of what was written.');
    }

    public function test_keys_the_file_does_not_carry_are_reported_but_not_stored(): void
    {
        $this->stageLoans();

        $response = $this->putJson("/api/imports/{$this->run->id}/product-mapping", [
            'Regular Loan' => $this->regular->id,
            'REGULAR   loan' => $this->regular->id,
            'Emergency' => $this->regular->id,
            'Typo Loan' => $this->regular->id,
        ])->assertOk();

        $this->assertSame(['Typo Loan'], $response->json('data.ignored_keys'));

        // Persisting a mapping for names that are not in the file would let an
        // unbounded request body become unbounded state in a JSON column.
        //
        // Canonicalizing, not assertSame: MySQL does not preserve JSON object
        // key order — it normalises keys by length then lexicographically, which
        // is why NormalizedRow stores its values as a positional LIST. Nothing
        // may ever depend on the order these keys come back in.
        $this->assertEqualsCanonicalizing(
            ['Regular Loan', 'REGULAR   loan', 'Emergency'],
            array_keys($this->run->fresh()->product_mapping),
        );
    }

    public function test_the_query_string_cannot_become_mapping_state(): void
    {
        $this->stageLoans();

        // A stray link parameter must not decide which product a cohort of
        // loans lands on.
        $this->putJson("/api/imports/{$this->run->id}/product-mapping?Injected%20Product={$this->regular->id}", [
            'Regular Loan' => $this->regular->id,
            'REGULAR   loan' => $this->regular->id,
            'Emergency' => $this->regular->id,
        ])->assertOk();

        $this->assertArrayNotHasKey('Injected Product', $this->run->fresh()->product_mapping);
    }

    public function test_a_value_that_is_not_a_product_id_is_named_by_its_value(): void
    {
        $this->stageLoans();

        $response = $this->putJson("/api/imports/{$this->run->id}/product-mapping", [
            'Regular Loan' => '3.5',
        ])->assertStatus(422);

        $message = $response->json('errors.product_mapping.0');

        $this->assertStringContainsString("'3.5'", $message, 'The message must name the rejected VALUE.');
        $this->assertStringContainsString('Regular Loan', $message);
    }

    public function test_an_oversized_mapping_is_refused_before_the_file_is_scanned(): void
    {
        $this->stageLoans();

        $oversized = [];

        for ($i = 0; $i <= ProductMappingResolver::MAX_CSV_PRODUCT_STRINGS; $i++) {
            $oversized["Product {$i}"] = $this->regular->id;
        }

        $this->putJson("/api/imports/{$this->run->id}/product-mapping", $oversized)
            ->assertStatus(422)
            ->assertJsonValidationErrors('product_mapping');

        $this->assertNull($this->run->fresh()->product_mapping);
    }

    public function test_a_loan_officer_may_not_read_or_confirm_the_mapping(): void
    {
        $this->stageLoans();

        $officer = $this->userWithRoleNamed('loan_officer');
        $this->assertFalse($officer->can('imports:process'));

        $this->actingAs($officer);

        $this->getJson("/api/imports/{$this->run->id}/product-mapping")->assertForbidden();
        $this->putJson("/api/imports/{$this->run->id}/product-mapping", [
            'Regular Loan' => $this->regular->id,
        ])->assertForbidden();

        $this->assertNull($this->run->fresh()->product_mapping);
    }
}
