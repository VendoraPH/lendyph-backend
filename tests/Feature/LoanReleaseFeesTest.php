<?php

namespace Tests\Feature;

use App\Models\AccountingJournal;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanReleaseFeeService;
use App\Services\LoanService;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The fee rules in Settings, actually applied.
 *
 * `Fee` was an orphaned model — FeeController wrote rows and nothing read them,
 * so the Fees screen was a form that saved into a void. These specs are the
 * other half: what a configured fee does to a loan at release.
 *
 * `FeeTest` covers the CRUD. Nothing here re-tests that.
 */
class LoanReleaseFeesTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * A product that charges NOTHING of its own.
     *
     * `LoanProductFactory` ships `processing_fee: 2.0` and `service_fee: 1.0`,
     * and `LoanService::createLoan()` turns those columns into deductions at
     * APPLICATION time through a mechanism that has nothing to do with `fees`.
     * Zeroing them is what makes the acceptance arithmetic below readable —
     * that the two mechanisms coexist is proved separately, in
     * {@see self::test_fees_are_added_to_the_deductions_the_product_columns_already_made()}.
     */
    private function freeProduct(array $overrides = []): LoanProduct
    {
        return LoanProduct::factory()->create(array_merge([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
            'processing_fee' => 0,
            'service_fee' => 0,
            'notarial_fee' => 0,
        ], $overrides));
    }

    private function approvedLoan(LoanProduct $product, float $principal = 10000, array $extra = []): Loan
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $loans = app(LoanService::class);

        $loan = $loans->createLoan(array_merge([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
        ], $extra), $this->admin);

        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');

        return $loan->fresh();
    }

    /** @return array<string, mixed>|null */
    private function lineNamed(array $deductions, string $name): ?array
    {
        foreach ($deductions as $item) {
            if (($item['name'] ?? null) === $name) {
                return $item;
            }
        }

        return null;
    }

    // ── The acceptance example from the handoff ──────────────────────────

    /**
     * ₱10,000 under Product A, a ₱500 fixed fee and a 2% fee assigned to A, and
     * a third fee assigned only to Product B.
     *
     * ₱500 + ₱200 = ₱700 withheld, ₱9,300 handed over. Product B's fee is not
     * in the list and not in the total.
     */
    public function test_acceptance_a_fixed_and_a_percentage_fee_withhold_seven_hundred_pesos(): void
    {
        $productA = $this->freeProduct();
        $productB = $this->freeProduct();

        $fixed = Fee::create([
            'name' => 'Documentary Fee',
            'type' => 'fixed',
            'value' => 500,
            'applicable_product_ids' => [$productA->id],
        ]);
        $percentage = Fee::create([
            'name' => 'Service Charge',
            'type' => 'percentage',
            'value' => 2.0,
            'applicable_product_ids' => [$productA->id],
        ]);
        Fee::create([
            'name' => 'Product B Only Fee',
            'type' => 'fixed',
            'value' => 999,
            'applicable_product_ids' => [$productB->id],
        ]);

        $loan = $this->approvedLoan($productA, 10000);

        $response = $this->patchJson("/api/loans/{$loan->id}/release", []);

        $response->assertOk()->assertJsonPath('data.status', 'released');

        $this->assertEquals(700.00, (float) $response->json('data.total_deductions'));
        $this->assertEquals(9300.00, (float) $response->json('data.net_proceeds'));

        $deductions = $response->json('data.deductions');
        $this->assertCount(2, $deductions);

        $this->assertEquals(
            ['name' => 'Documentary Fee', 'amount' => 500.0, 'type' => 'fixed', 'original_value' => 500.0, 'fee_id' => $fixed->id],
            $this->lineNamed($deductions, 'Documentary Fee'),
        );
        $this->assertEquals(
            ['name' => 'Service Charge', 'amount' => 200.0, 'type' => 'percentage', 'original_value' => 2.0, 'fee_id' => $percentage->id],
            $this->lineNamed($deductions, 'Service Charge'),
        );

        $this->assertNull($this->lineNamed($deductions, 'Product B Only Fee'));

        // The invariant the accounting poster asserts, restated here because it
        // is what makes the journal postable at all.
        $this->assertEquals(
            (float) $response->json('data.principal_amount'),
            (float) $response->json('data.total_deductions') + (float) $response->json('data.net_proceeds'),
        );
    }

    public function test_a_fee_assigned_only_to_another_product_never_applies(): void
    {
        $productA = $this->freeProduct();
        $productB = $this->freeProduct();

        Fee::create([
            'name' => 'B Handling Fee',
            'type' => 'fixed',
            'value' => 750,
            'applicable_product_ids' => [$productB->id],
        ]);

        $loan = $this->approvedLoan($productA, 10000);

        $response = $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();

        $this->assertEquals(0.0, (float) $response->json('data.total_deductions'));
        $this->assertEquals(10000.0, (float) $response->json('data.net_proceeds'));
        $this->assertSame([], $response->json('data.deductions'));
    }

    /**
     * NULL and `[]` are the SAME configuration: every product.
     *
     * The handoff asserts it and no code has ever implemented it. The API has
     * already committed to it regardless — `FeeResource` serialises
     * `applicable_product_ids ?? []`, so the two rows are indistinguishable to
     * every client that ever reads them back.
     */
    public function test_null_and_empty_applicable_product_ids_both_mean_every_product(): void
    {
        $product = $this->freeProduct();

        Fee::create(['name' => 'Null Scope Fee', 'type' => 'fixed', 'value' => 100, 'applicable_product_ids' => null]);
        Fee::create(['name' => 'Empty Scope Fee', 'type' => 'fixed', 'value' => 200, 'applicable_product_ids' => []]);

        $loan = $this->approvedLoan($product, 10000);

        $response = $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();

        $this->assertEquals(300.0, (float) $response->json('data.total_deductions'));
        $this->assertEquals(9700.0, (float) $response->json('data.net_proceeds'));
        $this->assertCount(2, $response->json('data.deductions'));
    }

    // ── Conditions ───────────────────────────────────────────────────────

    public function test_a_fee_whose_amount_condition_does_not_match_is_skipped(): void
    {
        $product = $this->freeProduct();

        Fee::create([
            'name' => 'Large Loan Fee',
            'type' => 'fixed',
            'value' => 400,
            'conditions' => ['loan_amount_gt' => 50000],
        ]);
        Fee::create([
            'name' => 'Small Loan Fee',
            'type' => 'fixed',
            'value' => 150,
            'conditions' => ['loan_amount_lt' => 50000],
        ]);

        $loan = $this->approvedLoan($product, 10000);

        $response = $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();

        $deductions = $response->json('data.deductions');
        $this->assertNull($this->lineNamed($deductions, 'Large Loan Fee'));
        $this->assertNotNull($this->lineNamed($deductions, 'Small Loan Fee'));
        $this->assertEquals(150.0, (float) $response->json('data.total_deductions'));
    }

    /** `gt` and `lt` are STRICT, so the boundary itself does not match. */
    public function test_amount_comparisons_are_strict_at_the_boundary(): void
    {
        $product = $this->freeProduct();

        Fee::create(['name' => 'Over Ten K', 'type' => 'fixed', 'value' => 10, 'conditions' => ['loan_amount_gt' => 10000]]);
        Fee::create(['name' => 'Under Ten K', 'type' => 'fixed', 'value' => 20, 'conditions' => ['loan_amount_lt' => 10000]]);
        Fee::create(['name' => 'Exactly Ten K', 'type' => 'fixed', 'value' => 30, 'conditions' => ['loan_amount_eq' => 10000]]);

        $loan = $this->approvedLoan($product, 10000);

        $deductions = $this->patchJson("/api/loans/{$loan->id}/release", [])
            ->assertOk()
            ->json('data.deductions');

        $this->assertNull($this->lineNamed($deductions, 'Over Ten K'));
        $this->assertNull($this->lineNamed($deductions, 'Under Ten K'));
        $this->assertNotNull($this->lineNamed($deductions, 'Exactly Ten K'));
    }

    /** Every populated key must hold — they are a conjunction, not alternatives. */
    public function test_all_populated_conditions_must_hold_together(): void
    {
        $product = $this->freeProduct();

        Fee::create([
            'name' => 'Both Conditions Fee',
            'type' => 'fixed',
            'value' => 60,
            'conditions' => ['loan_amount_gt' => 5000, 'term_days_gt' => 9000],
        ]);

        $loan = $this->approvedLoan($product, 10000);

        $deductions = $this->patchJson("/api/loans/{$loan->id}/release", [])
            ->assertOk()
            ->json('data.deductions');

        $this->assertNull($this->lineNamed($deductions, 'Both Conditions Fee'));
    }

    /**
     * `term_days_gt: 90` AND `term_days_lt: 30` is storable — nothing
     * cross-checks the six keys — and no loan can satisfy both.
     *
     * The decision: evaluate it literally, as the conjunction it is. The fee
     * never applies, quietly, and the release goes through. The alternative is
     * either guessing which of the two numbers the administrator meant (and
     * charging a borrower on a rule nobody wrote) or refusing to release a loan
     * because of a settings typo, with the borrower standing at the counter.
     */
    public function test_contradictory_conditions_make_a_fee_that_never_applies_rather_than_an_error(): void
    {
        $product = $this->freeProduct();

        Fee::create([
            'name' => 'Impossible Fee',
            'type' => 'fixed',
            'value' => 300,
            'conditions' => ['term_days_gt' => 90, 'term_days_lt' => 30],
        ]);

        $loan = $this->approvedLoan($product, 10000);

        $response = $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();

        $this->assertNull($this->lineNamed($response->json('data.deductions'), 'Impossible Fee'));
        $this->assertEquals(0.0, (float) $response->json('data.total_deductions'));
    }

    /**
     * Term days come from the AGREED DATES, never from `loans.term`.
     *
     * `term` is a period count whose unit follows `frequency`
     * ({@see LoanService::computeMaturityDate()}), so `term: 30` on a daily
     * loan is thirty DAYS and `term: 6` on a monthly one is about a hundred and
     * eighty. Any `term * k` conversion gets one of these two wrong.
     *
     * Both loans below are ₱10,000 with the same fee rule, `term_days_gt: 90`.
     * Read off `term`, the daily loan (30) and the monthly loan (6) would sort
     * the wrong way round or both fail; read off `start_date → maturity_date`,
     * only the six-month loan clears 90 days.
     */
    public function test_term_day_conditions_read_the_date_pair_not_the_period_count(): void
    {
        $sixMonths = $this->freeProduct(['term' => 6, 'frequency' => 'monthly']);
        $thirtyDays = $this->freeProduct(['term' => 30, 'frequency' => 'daily', 'max_term' => 30]);

        Fee::create([
            'name' => 'Long Term Fee',
            'type' => 'fixed',
            'value' => 250,
            'conditions' => ['term_days_gt' => 90],
        ]);

        $longLoan = $this->approvedLoan($sixMonths, 10000);
        $shortLoan = $this->approvedLoan($thirtyDays, 10000, ['term' => 30]);

        // The trap, stated as data: the SHORTER loan carries the LARGER `term`.
        $this->assertSame(30, (int) $shortLoan->term);
        $this->assertSame(6, (int) $longLoan->term);
        $this->assertSame(30, (int) $shortLoan->start_date->diffInDays($shortLoan->maturity_date));
        $this->assertGreaterThan(90, (int) $longLoan->start_date->diffInDays($longLoan->maturity_date));

        $long = $this->patchJson("/api/loans/{$longLoan->id}/release", [])->assertOk();
        $short = $this->patchJson("/api/loans/{$shortLoan->id}/release", [])->assertOk();

        $this->assertNotNull($this->lineNamed($long->json('data.deductions'), 'Long Term Fee'));
        $this->assertEquals(250.0, (float) $long->json('data.total_deductions'));

        $this->assertNull($this->lineNamed($short->json('data.deductions'), 'Long Term Fee'));
        $this->assertEquals(0.0, (float) $short->json('data.total_deductions'));
    }

    // ── Coexistence with the product-column mechanism ────────────────────

    /**
     * `createLoan()` already derives deductions from the loan product's own
     * `processing_fee` / `service_fee` / `notarial_fee` columns — a second,
     * parallel fee mechanism the handoff never mentions. Fees from Settings ADD
     * to those; they do not replace them.
     *
     * ₱10,000 at 2% processing + 1% service = ₱300 at application, plus a ₱500
     * fee at release = ₱800 withheld, ₱9,200 net.
     */
    public function test_fees_are_added_to_the_deductions_the_product_columns_already_made(): void
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
            'processing_fee' => 2.0,
            'service_fee' => 1.0,
            'notarial_fee' => 0,
        ]);

        Fee::create(['name' => 'Release Handling Fee', 'type' => 'fixed', 'value' => 500]);

        $loan = $this->approvedLoan($product, 10000);

        $this->assertEquals(300.0, (float) $loan->total_deductions, 'the product columns should have charged 3% at application');
        $this->assertCount(2, $loan->deductions);

        $response = $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();

        $deductions = $response->json('data.deductions');
        $this->assertCount(3, $deductions);

        $this->assertNotNull($this->lineNamed($deductions, 'Processing Fee'));
        $this->assertNotNull($this->lineNamed($deductions, 'Service Fee'));
        $this->assertNotNull($this->lineNamed($deductions, 'Release Handling Fee'));

        $this->assertEquals(800.0, (float) $response->json('data.total_deductions'));
        $this->assertEquals(9200.0, (float) $response->json('data.net_proceeds'));
    }

    /**
     * A `fees` row named exactly like a product column BOTH charge.
     *
     * Two independently configured charges that happen to share a label are two
     * charges. Suppressing one by name would mean a product column could
     * silently cancel a fee an administrator deliberately configured — and it
     * would break the moment either was renamed. Documented here as behaviour
     * rather than left to be discovered on a live release.
     */
    public function test_a_settings_fee_sharing_a_name_with_a_product_column_is_not_deduplicated(): void
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly',
            'penalty_rate' => 2.0, 'grace_period_days' => 3,
            'processing_fee' => 2.0, 'service_fee' => 0, 'notarial_fee' => 0,
        ]);

        Fee::create(['name' => 'Processing Fee', 'type' => 'fixed', 'value' => 50]);

        $loan = $this->approvedLoan($product, 10000);

        $deductions = $this->patchJson("/api/loans/{$loan->id}/release", [])
            ->assertOk()
            ->json('data.deductions');

        $named = array_values(array_filter($deductions, fn (array $d): bool => $d['name'] === 'Processing Fee'));
        $this->assertCount(2, $named, 'the product column and the Settings fee are two charges, not one');
        $this->assertEquals(200.0, (float) $named[0]['amount']);
        $this->assertEquals(50.0, (float) $named[1]['amount']);
    }

    // ── Insurance sits on top ────────────────────────────────────────────

    public function test_fees_and_a_partial_insurance_premium_both_land(): void
    {
        $product = $this->freeProduct();
        Fee::create(['name' => 'Release Fee', 'type' => 'percentage', 'value' => 2.0]);

        $loan = $this->approvedLoan($product, 10000);

        $response = $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 1.0,
            'insurance_premium_amount' => 100,
            'insurance_payment_type' => 'partial',
            'insurance_partial_amount' => 60,
            'insurance_remaining_balance' => 40,
        ])->assertOk();

        $deductions = $response->json('data.deductions');
        $this->assertCount(2, $deductions);
        $this->assertEquals(200.0, (float) $this->lineNamed($deductions, 'Release Fee')['amount']);
        $this->assertEquals(60.0, (float) $this->lineNamed($deductions, 'Insurance Premium')['amount']);

        // ₱200 of fee + ₱60 of premium collected at the counter.
        $this->assertEquals(260.0, (float) $response->json('data.total_deductions'));
        $this->assertEquals(9740.0, (float) $response->json('data.net_proceeds'));
        $this->assertEquals(40.0, (float) $response->json('data.insurance_remaining_balance'));

        // The fee line comes FIRST, because fees are applied before the
        // insurance block. The ordering is what decides which guard speaks when
        // the withholdings overrun the principal.
        $this->assertSame('Release Fee', $deductions[0]['name']);
        $this->assertSame('Insurance Premium', $deductions[1]['name']);
    }

    // ── Guards ───────────────────────────────────────────────────────────

    /**
     * Fees larger than the principal are refused as a FEE problem, and the
     * release is rolled back whole.
     *
     * The insurance figures in this payload are valid and innocent. Running the
     * fee block before the insurance block is what makes the message name the
     * configuration the operator has to go and fix, rather than the one number
     * the cashier just typed.
     */
    public function test_fees_exceeding_the_principal_are_refused_as_a_fee_problem(): void
    {
        $product = $this->freeProduct();
        Fee::create(['name' => 'Runaway Fee', 'type' => 'fixed', 'value' => 12000]);

        $loan = $this->approvedLoan($product, 10000);

        $response = $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 1.0,
            'insurance_premium_amount' => 100,
            'insurance_payment_type' => 'full',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['fees']);
        $this->assertStringContainsString('12,000.00', $response->json('errors.fees.0'));
        $this->assertStringContainsString('Settings', $response->json('errors.fees.0'));
        $this->assertArrayNotHasKey('insurance_premium_amount', $response->json('errors'));

        // Rolled back whole: no status change, no loan account number, no
        // schedule, no deductions.
        $fresh = Loan::find($loan->id);
        $this->assertSame('approved', $fresh->status);
        $this->assertNull($fresh->loan_account_number);
        $this->assertEquals(0.0, (float) $fresh->total_deductions);
        $this->assertSame(0, $fresh->amortizationSchedules()->count());
    }

    /**
     * The other side of that ordering: fees that FIT, and an insurance premium
     * that does not. Now the insurance guard is the one that speaks, which is
     * correct — the premium really is what overran.
     */
    public function test_an_overlarge_premium_still_reports_as_an_insurance_problem(): void
    {
        $product = $this->freeProduct();
        Fee::create(['name' => 'Modest Fee', 'type' => 'fixed', 'value' => 500]);

        $loan = $this->approvedLoan($product, 10000);

        $this->patchJson("/api/loans/{$loan->id}/release", [
            'insurance_premium_percentage' => 99.0,
            'insurance_premium_amount' => 9900,
            'insurance_payment_type' => 'full',
        ])->assertUnprocessable()->assertJsonValidationErrors(['insurance_premium_amount']);

        $this->assertSame('approved', Loan::find($loan->id)->status);
    }

    // ── Idempotency ──────────────────────────────────────────────────────

    public function test_a_second_release_request_is_refused_and_charges_nothing_further(): void
    {
        $product = $this->freeProduct();
        Fee::create(['name' => 'One Time Fee', 'type' => 'fixed', 'value' => 500]);

        $loan = $this->approvedLoan($product, 10000);

        $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();

        $this->patchJson("/api/loans/{$loan->id}/release", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $fresh = Loan::find($loan->id);
        $this->assertCount(1, $fresh->deductions);
        $this->assertEquals(500.0, (float) $fresh->total_deductions);
        $this->assertEquals(9500.0, (float) $fresh->net_proceeds);
    }

    /**
     * Idempotency keyed on `fee_id`, proved by driving the fee step twice
     * directly — the status guard on `release()` would otherwise hide it.
     *
     * The second call also happens AFTER a rename, which is exactly what would
     * defeat matching by name.
     */
    public function test_applying_fees_twice_charges_once_even_after_the_fee_is_renamed(): void
    {
        $product = $this->freeProduct();
        $fee = Fee::create(['name' => 'Original Name Fee', 'type' => 'percentage', 'value' => 2.0]);

        $loan = $this->approvedLoan($product, 10000);
        $fees = app(LoanReleaseFeeService::class);

        $fees->applyOnRelease($loan);
        $this->assertEquals(200.0, (float) $loan->fresh()->total_deductions);

        $fee->update(['name' => 'Renamed Fee']);

        $fees->applyOnRelease($loan->fresh());

        $fresh = Loan::find($loan->id);
        $this->assertCount(1, $fresh->deductions);
        $this->assertSame('Original Name Fee', $fresh->deductions[0]['name']);
        $this->assertSame($fee->id, $fresh->deductions[0]['fee_id']);
        $this->assertEquals(200.0, (float) $fresh->total_deductions);
        $this->assertEquals(9800.0, (float) $fresh->net_proceeds);
    }

    /**
     * A fee added between two applications IS charged — which is precisely what
     * counting the existing deductions ("N already applied, skip") would get
     * wrong.
     */
    public function test_a_fee_created_after_the_first_application_is_still_charged(): void
    {
        $product = $this->freeProduct();
        Fee::create(['name' => 'First Fee', 'type' => 'fixed', 'value' => 100]);

        $loan = $this->approvedLoan($product, 10000);
        $fees = app(LoanReleaseFeeService::class);

        $fees->applyOnRelease($loan);

        Fee::create(['name' => 'Second Fee', 'type' => 'fixed', 'value' => 250]);

        $fees->applyOnRelease($loan->fresh());

        $fresh = Loan::find($loan->id);
        $this->assertCount(2, $fresh->deductions);
        $this->assertEquals(350.0, (float) $fresh->total_deductions);
    }

    // ── Decimals and centavos ────────────────────────────────────────────

    /**
     * `Fee::value` is `decimal:4` and arrives as the STRING "1.2345", while
     * `total_deductions` is `decimal:2` and arrives as "0.00". Add them without
     * casting and PHP is as likely to concatenate as to sum — a bug this
     * project has already shipped once.
     *
     * 1.2345% of ₱10,000 is ₱123.45 exactly, and ₱123.45 is not "01.2345",
     * "1.23450.00", 1.2345 or 0.
     */
    public function test_a_four_decimal_percentage_is_summed_rather_than_concatenated(): void
    {
        $product = $this->freeProduct();
        $fee = Fee::create(['name' => 'Fractional Fee', 'type' => 'percentage', 'value' => 1.2345]);

        $this->assertSame('1.2345', (string) $fee->fresh()->value, 'the cast should hand back a 4dp string');

        $loan = $this->approvedLoan($product, 10000);

        $response = $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();

        $this->assertSame('123.45', (string) Loan::find($loan->id)->total_deductions);
        $this->assertSame('9876.55', (string) Loan::find($loan->id)->net_proceeds);
        $this->assertEquals(123.45, (float) $this->lineNamed($response->json('data.deductions'), 'Fractional Fee')['amount']);
    }

    /** A fee configured at zero produces no line at all. */
    public function test_a_zero_valued_fee_adds_no_deduction_line(): void
    {
        $product = $this->freeProduct();
        Fee::create(['name' => 'Waived Fee', 'type' => 'fixed', 'value' => 0]);

        $response = $this->patchJson("/api/loans/{$this->approvedLoan($product, 10000)->id}/release", [])->assertOk();

        $this->assertSame([], $response->json('data.deductions'));
        $this->assertEquals(0.0, (float) $response->json('data.total_deductions'));
    }

    /**
     * Money is `decimal:2` PESOS in lending and integer CENTAVOS in accounting,
     * and a fee reaches the ledger through the release poster. A 100× error
     * anywhere on that path is the kind that balances perfectly and misstates
     * everything.
     *
     * ₱50,000 with a ₱1,234.56 fixed fee and a 0.5% fee (₱250.00):
     * ₱1,484.56 withheld, ₱48,515.44 disbursed. The figures are deliberately
     * not round — ₱1,234.56 is 123,456 centavos and could never be mistaken for
     * its own peso figure, and 48,515.44 would show a factor-of-100 slip in the
     * first digit.
     */
    public function test_fee_pesos_reach_the_books_as_centavos_by_a_factor_of_exactly_one_hundred(): void
    {
        $this->seedChartOfAccounts();

        $product = $this->freeProduct();
        Fee::create(['name' => 'Exact Fixed Fee', 'type' => 'fixed', 'value' => 1234.56]);
        Fee::create(['name' => 'Half Percent Fee', 'type' => 'percentage', 'value' => 0.5]);

        $loan = $this->approvedLoan($product, 50000);

        $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();

        $fresh = Loan::find($loan->id);
        $this->assertSame('50000.00', (string) $fresh->principal_amount);
        $this->assertSame('1484.56', (string) $fresh->total_deductions);
        $this->assertSame('48515.44', (string) $fresh->net_proceeds);

        $journal = AccountingJournal::query()
            ->where('postable_type', $fresh->getMorphClass())
            ->where('postable_id', $fresh->getKey())
            ->where('source', 'loan_release')
            ->firstOrFail();

        $lineOn = function (string $code, string $side) use ($journal): int {
            $line = $journal->lines()->where('accounting_account_id', $this->account($code))->first();

            return $line === null ? 0 : (int) $line->{$side};
        };

        $this->assertSame(5_000_000, $lineOn('1110', 'debit'));
        $this->assertSame(148_456, $lineOn('4030', 'credit'));
        $this->assertSame(4_851_544, $lineOn('1010', 'credit'));

        // Not the peso figures wearing a centavo label.
        $this->assertNotSame(1_484, $lineOn('4030', 'credit'));
        $this->assertNotSame(48_515, $lineOn('1010', 'credit'));
    }

    // ── The preview ──────────────────────────────────────────────────────

    public function test_the_release_preview_reports_what_the_release_would_do(): void
    {
        $product = $this->freeProduct();
        Fee::create(['name' => 'Preview Fixed Fee', 'type' => 'fixed', 'value' => 500]);
        Fee::create(['name' => 'Preview Percentage Fee', 'type' => 'percentage', 'value' => 2.0]);

        $loan = $this->approvedLoan($product, 10000);

        $preview = $this->getJson("/api/loans/{$loan->id}/release-preview")->assertOk();

        // 2dp STRINGS, the same wire shape LoanResource gives these two fields,
        // so a client can hold the previewed figure next to the released one
        // without knowing which type it has.
        $this->assertSame('700.00', $preview->json('data.total_deductions'));
        $this->assertSame('9300.00', $preview->json('data.net_proceeds'));
        $this->assertCount(2, $preview->json('data.deductions'));
        $this->assertNotEmpty($preview->json('data.fee_fingerprint'));

        // Read-only: nothing moved.
        $this->assertSame('approved', Loan::find($loan->id)->status);
        $this->assertEquals(0.0, (float) Loan::find($loan->id)->total_deductions);

        // And the release agrees with it, to the centavo.
        $release = $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();
        $this->assertSame($preview->json('data.total_deductions'), $release->json('data.total_deductions'));
        $this->assertSame($preview->json('data.net_proceeds'), $release->json('data.net_proceeds'));
        $this->assertSame($preview->json('data.deductions'), $release->json('data.deductions'));
    }

    public function test_the_release_preview_needs_the_release_permission_not_merely_view(): void
    {
        $product = $this->freeProduct();
        $loan = $this->approvedLoan($product, 10000);

        $collector = User::factory()->create(['branch_id' => $this->branch->id]);
        $collector->assignRole('collector');
        $this->actingAs($collector)
            ->getJson("/api/loans/{$loan->id}/release-preview")
            ->assertForbidden();

        $cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $cashier->assignRole('cashier');
        $this->actingAs($cashier)
            ->getJson("/api/loans/{$loan->id}/release-preview")
            ->assertOk();
    }

    public function test_the_release_preview_is_refused_for_a_loan_that_is_not_awaiting_release(): void
    {
        $product = $this->freeProduct();
        $loan = $this->approvedLoan($product, 10000);

        $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();

        $this->getJson("/api/loans/{$loan->id}/release-preview")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    /** The preview refuses an unreleasable configuration too, not just the release. */
    public function test_the_release_preview_refuses_fees_that_exceed_the_principal(): void
    {
        $product = $this->freeProduct();
        Fee::create(['name' => 'Runaway Preview Fee', 'type' => 'fixed', 'value' => 12000]);

        $loan = $this->approvedLoan($product, 10000);

        $this->getJson("/api/loans/{$loan->id}/release-preview")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fees']);
    }

    // ── The fingerprint ──────────────────────────────────────────────────

    public function test_a_release_quoting_a_stale_fee_fingerprint_is_refused_with_409(): void
    {
        $product = $this->freeProduct();
        $fee = Fee::create(['name' => 'Movable Fee', 'type' => 'fixed', 'value' => 500]);

        $loan = $this->approvedLoan($product, 10000);

        $fingerprint = $this->getJson("/api/loans/{$loan->id}/release-preview")
            ->assertOk()
            ->json('data.fee_fingerprint');

        // Somebody edits the fee schedule while the cashier reads the screen.
        $fee->update(['value' => 900]);

        $response = $this->patchJson("/api/loans/{$loan->id}/release", [
            'fee_fingerprint' => $fingerprint,
        ]);

        $response->assertStatus(409)->assertJsonValidationErrors(['fee_fingerprint']);
        $this->assertSame($fingerprint, $response->json('data.expected_fee_fingerprint'));
        $this->assertNotSame($fingerprint, $response->json('data.current_fee_fingerprint'));

        // Nothing was disbursed and nothing was written.
        $fresh = Loan::find($loan->id);
        $this->assertSame('approved', $fresh->status);
        $this->assertNull($fresh->loan_account_number);
        $this->assertEquals(0.0, (float) $fresh->total_deductions);
    }

    public function test_a_release_quoting_the_current_fingerprint_goes_through(): void
    {
        $product = $this->freeProduct();
        Fee::create(['name' => 'Stable Fee', 'type' => 'fixed', 'value' => 500]);

        $loan = $this->approvedLoan($product, 10000);

        $fingerprint = $this->getJson("/api/loans/{$loan->id}/release-preview")
            ->assertOk()
            ->json('data.fee_fingerprint');

        $this->patchJson("/api/loans/{$loan->id}/release", ['fee_fingerprint' => $fingerprint])
            ->assertOk()
            ->assertJsonPath('data.total_deductions', '500.00');
    }

    /**
     * A fee ADDED after the preview is a mismatch too — the previewed empty set
     * has a fingerprint of its own, so "no fees then, one fee now" is caught.
     */
    public function test_a_fee_added_after_the_preview_invalidates_the_fingerprint(): void
    {
        $product = $this->freeProduct();
        $loan = $this->approvedLoan($product, 10000);

        $fingerprint = $this->getJson("/api/loans/{$loan->id}/release-preview")
            ->assertOk()
            ->json('data.fee_fingerprint');

        Fee::create(['name' => 'Latecomer Fee', 'type' => 'fixed', 'value' => 100]);

        $this->patchJson("/api/loans/{$loan->id}/release", ['fee_fingerprint' => $fingerprint])
            ->assertStatus(409);
    }

    /**
     * A fee that could never apply to THIS loan changing must not block it.
     *
     * The fingerprint covers the applicable rows only, which is what keeps an
     * unrelated Settings edit from failing a release it has no bearing on.
     */
    public function test_editing_an_inapplicable_fee_does_not_invalidate_the_fingerprint(): void
    {
        $productA = $this->freeProduct();
        $productB = $this->freeProduct();

        Fee::create(['name' => 'A Fee', 'type' => 'fixed', 'value' => 500, 'applicable_product_ids' => [$productA->id]]);
        $other = Fee::create(['name' => 'B Fee', 'type' => 'fixed', 'value' => 700, 'applicable_product_ids' => [$productB->id]]);

        $loan = $this->approvedLoan($productA, 10000);

        $fingerprint = $this->getJson("/api/loans/{$loan->id}/release-preview")
            ->assertOk()
            ->json('data.fee_fingerprint');

        $other->update(['value' => 4200]);

        $this->patchJson("/api/loans/{$loan->id}/release", ['fee_fingerprint' => $fingerprint])
            ->assertOk()
            ->assertJsonPath('data.total_deductions', '500.00');
    }

    /** No fingerprint at all is fine — which is how the current frontend releases. */
    public function test_a_release_without_a_fingerprint_is_not_refused(): void
    {
        $product = $this->freeProduct();
        $fee = Fee::create(['name' => 'Unquoted Fee', 'type' => 'fixed', 'value' => 500]);

        $loan = $this->approvedLoan($product, 10000);

        $this->getJson("/api/loans/{$loan->id}/release-preview")->assertOk();
        $fee->update(['value' => 750]);

        $this->patchJson("/api/loans/{$loan->id}/release", [])
            ->assertOk()
            ->assertJsonPath('data.total_deductions', '750.00');
    }

    // ── Audit ────────────────────────────────────────────────────────────

    public function test_applying_fees_writes_an_audit_entry_naming_what_was_charged(): void
    {
        $product = $this->freeProduct();
        Fee::create(['name' => 'Audited Fee', 'type' => 'fixed', 'value' => 500]);

        $loan = $this->approvedLoan($product, 10000);

        $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();

        $entry = AuditLog::where('action', 'release_fees')
            ->where('auditable_id', $loan->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertEquals(500.0, $entry->new_values['charged_at_release']);
        $this->assertSame('Audited Fee', $entry->new_values['fees'][0]['name']);
    }

    public function test_no_audit_entry_is_written_when_no_fee_applies(): void
    {
        $product = $this->freeProduct();

        $loan = $this->approvedLoan($product, 10000);

        $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();

        $this->assertSame(
            0,
            AuditLog::where('action', 'release_fees')->where('auditable_id', $loan->id)->count(),
        );
    }
}
