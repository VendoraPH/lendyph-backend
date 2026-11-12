<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\FeeAmount;
use App\Services\LoanReleaseFeeService;
use App\Services\LoanService;
use App\Services\LoanTermSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A Formula fee written as an expression (Phase 4, B1): saved and refused in
 * words, worked out on the server with exact maths, charged at release with a
 * snapshot of what it was worked out from, and previewed in Settings by
 * POST /fees/formula-check.
 */
class FeeFormulaExpressionTest extends TestCase
{
    use SetupLendyPH;

    private const CONTRACT_EXAMPLE = '(Principal Amount * 2.5%) / 365';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** A product charging nothing of its own, so only the catalog fee shows. */
    private function freeProduct(array $overrides = []): LoanProduct
    {
        return LoanProduct::factory()->create(array_merge([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'term_unit' => 'months',
            'frequency' => 'monthly',
            'processing_fee' => 0,
            'service_fee' => 0,
            'notarial_fee' => 0,
            'min_amount' => 1000,
            'max_amount' => 1000000,
        ], $overrides));
    }

    private function approvedLoan(LoanProduct $product, float $principal = 10000): Loan
    {
        $loans = app(LoanService::class);
        $loan = $loans->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
            'deductions' => [],
        ], $this->admin);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');

        return $loan->fresh();
    }

    private function expressionFee(LoanProduct $product, string $expression, array $overrides = []): Fee
    {
        return Fee::create(array_merge([
            'name' => 'Expression Fee',
            'type' => 'formula',
            'value' => 0,
            'per_month' => false,
            'formula_expression' => $expression,
            'applicable_product_ids' => [$product->id],
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function formPreviewBody(LoanProduct $product, float $principal): array
    {
        return [
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'interest_rate' => 3,
            'term' => $product->term,
            'frequency' => $product->frequency,
            'start_date' => now()->toDateString(),
            'deductions' => [],
        ];
    }

    private function officer(): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $user->syncRoles(['loan_officer']);

        return $user;
    }

    // ── Settings API ─────────────────────────────────────────────────────

    public function test_an_expression_fee_is_saved_with_value_zero_and_no_per_month(): void
    {
        $this->postJson('/api/fees', [
            'name' => 'Daily Handling',
            'type' => 'formula',
            'value' => 2.5,
            'per_month' => true,
            'min_amount' => 5,
            'formula_expression' => '  '.self::CONTRACT_EXAMPLE.' ',
        ])->assertCreated()
            ->assertJsonPath('data.formula_expression', self::CONTRACT_EXAMPLE)
            ->assertJsonPath('data.value', 0)
            ->assertJsonPath('data.per_month', false)
            ->assertJsonPath('data.min_amount', 5);

        $this->assertDatabaseHas('fees', ['name' => 'Daily Handling', 'value' => 0, 'per_month' => 0, 'formula_expression' => self::CONTRACT_EXAMPLE]);

        // No rate is needed when the formula is written out.
        $this->postJson('/api/fees', ['name' => 'No Rate', 'type' => 'formula', 'formula_expression' => 'Loan Amount × 1%'])
            ->assertCreated()->assertJsonPath('data.value', 0);
    }

    public function test_any_other_type_or_an_empty_expression_clears_it(): void
    {
        $this->postJson('/api/fees', ['name' => 'Flat', 'type' => 'fixed', 'value' => 150, 'formula_expression' => 'Loan Amount * 1%'])
            ->assertCreated()->assertJsonPath('data.formula_expression', null);

        $this->postJson('/api/fees', ['name' => 'Recipe', 'type' => 'formula', 'value' => 1.5, 'formula_expression' => ''])
            ->assertCreated()->assertJsonPath('data.formula_expression', null)->assertJsonPath('data.value', 1.5);

        $fee = Fee::create(['name' => 'Switching', 'type' => 'formula', 'value' => 0, 'formula_expression' => 'Loan Amount * 1%']);

        $this->putJson("/api/fees/{$fee->id}", ['type' => 'percentage', 'value' => 2])
            ->assertOk()->assertJsonPath('data.formula_expression', null)->assertJsonPath('data.type', 'percentage');
        $this->assertNull($fee->fresh()->formula_expression);
    }

    public function test_an_update_keeps_the_stored_expression_and_value_zero(): void
    {
        $fee = Fee::create(['name' => 'Kept', 'type' => 'formula', 'value' => 0, 'formula_expression' => 'Loan Amount * 1%']);

        $this->putJson("/api/fees/{$fee->id}", ['value' => 4, 'per_month' => true, 'max_amount' => 900])
            ->assertOk()
            ->assertJsonPath('data.formula_expression', 'Loan Amount * 1%')
            ->assertJsonPath('data.value', 0)
            ->assertJsonPath('data.per_month', false)
            ->assertJsonPath('data.max_amount', 900);

        // Back to "rate of principal" needs the rate, since the stored one is 0.
        $this->putJson("/api/fees/{$fee->id}", ['formula_expression' => null])
            ->assertUnprocessable()
            ->assertJsonPath('errors.value.0', 'Enter the rate when a formula fee goes back to a rate of the principal.');

        $this->putJson("/api/fees/{$fee->id}", ['formula_expression' => null, 'value' => 1.25])
            ->assertOk()->assertJsonPath('data.formula_expression', null)->assertJsonPath('data.value', 1.25);
    }

    public function test_a_type_change_off_a_written_formula_needs_a_rate(): void
    {
        $fee = Fee::create(['name' => 'Written', 'type' => 'formula', 'value' => 0, 'formula_expression' => 'Loan Amount * 1%']);

        $this->putJson("/api/fees/{$fee->id}", ['type' => 'percentage'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.value', ['Enter the rate when a fee written as a formula changes to a percentage.']);
        $this->assertSame('Loan Amount * 1%', $fee->fresh()->formula_expression);

        $this->putJson("/api/fees/{$fee->id}", ['type' => 'percentage', 'value' => 1.5])
            ->assertOk()->assertJsonPath('data.value', 1.5)->assertJsonPath('data.formula_expression', null);
    }

    public function test_a_switch_from_a_written_formula_to_fixed_needs_an_amount(): void
    {
        $fee = Fee::create(['name' => 'Written', 'type' => 'formula', 'value' => 0, 'formula_expression' => 'Loan Amount * 1%']);

        $this->putJson("/api/fees/{$fee->id}", ['type' => 'fixed'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.value', ['Enter the amount when a fee written as a formula changes to a fixed amount.']);
        $this->assertSame('formula', $fee->fresh()->type);

        $this->putJson("/api/fees/{$fee->id}", ['type' => 'fixed', 'value' => 150])
            ->assertOk()->assertJsonPath('data.value', 150)->assertJsonPath('data.formula_expression', null);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedFormulas(): array
    {
        $list = 'Use a variable name from the list: Principal Amount, Loan Amount, Interest Rate, Loan Term or Term in Days.';
        $stray = 'Use only numbers, the variables, + − × ÷ % and brackets.';
        $tooLong = 'The formula is too long (at most 255 characters, 64 parts and 10 brackets deep).';

        return [
            'unknown name' => ['Principle * 2%', "{$list} 'Principle' isn't one."],
            'semicolon' => ['Loan Amount * 2%;', $stray],
            'caret' => ['Loan Amount ^ 2', $stray],
            'dollar' => ['$Loan Amount', $stray],
            'stray letters' => ['Loan Amount * 2% abc', "{$list} 'abc' isn't one."],
            'unbalanced' => ['(Loan Amount * 2%', 'A bracket is opened and never closed.'],
            'literal divide by zero' => ['Loan Amount ÷ (3 − 3)', 'The formula divides by zero.'],
            'no variable' => ['1000 * 2%', 'The formula needs at least one variable; for a fixed amount use the Fixed type.'],
            'too many characters' => ['Loan Amount'.str_repeat(' ', 245).'* 1%', $tooLong],
            'too many parts' => ['Loan Amount'.str_repeat(' + 1', 32), $tooLong],
            'too deep' => [str_repeat('(', 11).'Loan Amount'.str_repeat(')', 11), $tooLong],
        ];
    }

    #[DataProvider('refusedFormulas')]
    public function test_a_bad_formula_is_refused_at_save_in_words(string $formula, string $message): void
    {
        $this->postJson('/api/fees', ['name' => 'Bad', 'type' => 'formula', 'formula_expression' => $formula])
            ->assertUnprocessable()
            ->assertJsonPath('errors.formula_expression', [$message]);

        $fee = Fee::create(['name' => 'Stored', 'type' => 'formula', 'value' => 1]);

        $this->putJson("/api/fees/{$fee->id}", ['formula_expression' => $formula])
            ->assertUnprocessable()
            ->assertJsonPath('errors.formula_expression', [$message]);

        $this->assertDatabaseMissing('fees', ['name' => 'Bad']);
        $this->assertNull($fee->fresh()->formula_expression);
    }

    // ── What the release charges ─────────────────────────────────────────

    public function test_the_release_charges_the_previewed_item_with_its_snapshot(): void
    {
        $product = $this->freeProduct();
        $fee = $this->expressionFee($product, self::CONTRACT_EXAMPLE);
        $loan = $this->approvedLoan($product, 100000);
        $days = (string) (int) $loan->start_date->diffInDays($loan->maturity_date);

        $expected = [
            'name' => 'Expression Fee',
            'amount' => 6.85,
            'type' => 'formula',
            'original_value' => 0.0,
            'per_month' => false,
            'min_amount' => null,
            'max_amount' => null,
            'formula_expression' => self::CONTRACT_EXAMPLE,
            'formula_inputs' => [
                'principal_amount' => '100000.00',
                'loan_amount' => '100000.00',
                'interest_rate' => '3.0000',
                'interest_rate_frequency' => 'monthly',
                'loan_term' => '6',
                'term_in_days' => $days,
            ],
            'raw_amount' => '6.849315',
            'fee_id' => $fee->id,
        ];

        $preview = $this->getJson("/api/loans/{$loan->id}/release-preview")->assertOk()->json('data');
        $this->assertEquals([$expected], $preview['deductions']);
        $this->assertSame('99993.15', $preview['net_proceeds']);

        $form = $this->postJson('/api/loans/preview', $this->formPreviewBody($product, 100000))->assertOk()->json('data.deductions');
        $this->assertEquals([$expected], $form['configured_fees']);

        $release = $this->patchJson("/api/loans/{$loan->id}/release", ['fee_fingerprint' => $preview['fee_fingerprint']])->assertOk()->json('data');
        $this->assertEquals($preview['deductions'], $release['deductions']);
        $this->assertSame($preview['net_proceeds'], $release['net_proceeds']);

        // Later fee edits never change what the released loan stored.
        $stored = $loan->fresh()->deductions;
        $this->putJson("/api/fees/{$fee->id}", ['formula_expression' => 'Loan Amount * 5%', 'min_amount' => 100])->assertOk();
        $this->assertSame($stored, $loan->fresh()->deductions);
        $this->assertEquals([$expected], $stored);
    }

    public function test_a_recompute_reads_an_expression_item_from_its_snapshot(): void
    {
        $product = $this->freeProduct();
        $fee = $this->expressionFee($product, self::CONTRACT_EXAMPLE);
        $loan = $this->approvedLoan($product, 100000);
        $this->patchJson("/api/loans/{$loan->id}/release", [])->assertOk();
        $stored = $loan->fresh()->deductions;

        // Today's fee says something else; the item keeps what it was charged with.
        $fee->update(['formula_expression' => 'Loan Amount * 5%']);

        $loans = app(LoanService::class);
        $inputs = (fn (array $items): array => $this->deductionInputsFrom($items))->call($loans, $stored);
        $items = $loans->computeDeductions(100000, $inputs, FeeAmount::termMonths(6, 'months'))['items'];

        $expected = $stored[0];
        unset($expected['fee_id']);
        $this->assertEquals([$expected], $items);
    }

    public function test_the_owners_days_formula_in_unicode_charges_ten_pesos(): void
    {
        $product = $this->freeProduct(['term' => 30, 'term_unit' => 'days', 'frequency' => 'daily', 'interest_rate_frequency' => 'daily']);
        $this->expressionFee($product, 'Loan Amount ÷ Term in Days × 3%');
        $loan = $this->approvedLoan($product, 10000);

        $item = $this->getJson("/api/loans/{$loan->id}/release-preview")->assertOk()->json('data.deductions.0');

        $this->assertEquals(10.0, $item['amount']);
        $this->assertSame('10.000000', $item['raw_amount']);
        $this->assertSame(['principal_amount' => '10000.00', 'loan_amount' => '10000.00', 'interest_rate' => '3.0000', 'interest_rate_frequency' => 'daily', 'loan_term' => '1', 'term_in_days' => '30'], $item['formula_inputs']);
    }

    public function test_the_form_preview_passes_the_rate_it_is_given(): void
    {
        $product = $this->freeProduct();
        $this->expressionFee($product, 'Loan Amount × Interest Rate% ÷ 10');

        $body = [...$this->formPreviewBody($product, 10000), 'interest_rate' => 2.5];
        $item = $this->postJson('/api/loans/preview', $body)->assertOk()->json('data.deductions.configured_fees.0');

        // 2.5% of ₱10,000.00, over 10.
        $this->assertEquals(25.0, $item['amount']);
        $this->assertSame('2.5000', $item['formula_inputs']['interest_rate']);
        $this->assertSame('monthly', $item['formula_inputs']['interest_rate_frequency']);
    }

    public function test_the_bounds_clamp_an_expression_fee(): void
    {
        $product = $this->freeProduct();
        $this->expressionFee($product, self::CONTRACT_EXAMPLE, ['min_amount' => 20, 'max_amount' => 50]);

        $item = $this->getJson("/api/loans/{$this->approvedLoan($product, 100000)->id}/release-preview")->assertOk()->json('data.deductions.0');

        $this->assertEquals(20.0, $item['amount']);
        $this->assertSame('6.849315', $item['raw_amount']);
    }

    public function test_an_expression_edited_since_the_preview_is_refused_with_409(): void
    {
        $product = $this->freeProduct();
        $fee = $this->expressionFee($product, self::CONTRACT_EXAMPLE);
        $loan = $this->approvedLoan($product, 100000);

        $fingerprint = $this->getJson("/api/loans/{$loan->id}/release-preview")->assertOk()->json('data.fee_fingerprint');

        $fee->update(['formula_expression' => '(Principal Amount * 2.5%) / 360']);

        $this->patchJson("/api/loans/{$loan->id}/release", ['fee_fingerprint' => $fingerprint])
            ->assertStatus(409)->assertJsonValidationErrors(['fee_fingerprint']);
        $this->assertSame('approved', $loan->fresh()->status);
    }

    public function test_a_recipe_formula_fee_keeps_its_fingerprint(): void
    {
        $product = $this->freeProduct();
        $fee = Fee::create(['name' => 'Recipe', 'type' => 'formula', 'value' => 1, 'per_month' => true, 'min_amount' => 10, 'max_amount' => null, 'applicable_product_ids' => [$product->id]]);

        $expected = hash('sha256', json_encode([[(int) $fee->id, 'Recipe', 'formula', '1.0000', true, '10.00', '']], JSON_THROW_ON_ERROR));

        $this->assertSame($expected, app(LoanReleaseFeeService::class)->fingerprint([$fee->fresh()]));
    }

    public function test_a_zero_term_in_days_divisor_answers_422_naming_the_fee(): void
    {
        $product = $this->freeProduct();
        $this->expressionFee($product, 'Loan Amount / Term in Days * 3%', ['name' => 'Daily Charge']);
        $loan = $this->approvedLoan($product, 10000);
        DB::table('loans')->where('id', $loan->id)->update(['maturity_date' => $loan->start_date->toDateString()]);

        $message = 'The fee "Daily Charge" can\'t be worked out for this loan: it divides by zero (Term in Days is 0).';

        $this->getJson("/api/loans/{$loan->id}/release-preview")->assertUnprocessable()->assertJsonPath('errors.fees', [$message]);
        $this->getJson("/api/loans/{$loan->id}/deductions-preview")->assertUnprocessable()->assertJsonPath('errors.fees', [$message]);
        $this->getJson("/api/loans/{$loan->id}/disclosure")->assertUnprocessable()->assertJsonPath('errors.fees', [$message]);
        $this->patchJson("/api/loans/{$loan->id}/release", [])->assertUnprocessable()->assertJsonPath('errors.fees', [$message]);
        $this->assertSame('approved', $loan->fresh()->status);
    }

    public function test_a_divisor_that_works_out_to_zero_on_the_form_preview_is_reported_in_the_preview(): void
    {
        $product = $this->freeProduct();
        $this->expressionFee($product, 'Loan Amount / (Loan Term − 6)', ['name' => 'Odd Fee']);

        $preview = $this->postJson('/api/loans/preview', $this->formPreviewBody($product, 10000))->assertOk()->json('data');

        $this->assertSame('The fee "Odd Fee" can\'t be worked out for this loan: it divides by zero.', $preview['deductions']['error']);
        $this->assertSame([], $preview['deductions']['configured_fees']);
        $this->assertNull($preview['deductions']['net_proceeds']);
        $this->assertNotNull($preview['amortization']);
    }

    public function test_the_form_preview_falls_back_to_the_products_rate_as_the_create_does(): void
    {
        $product = $this->freeProduct(['interest_rate' => 3.0, 'interest_rate_frequency' => 'weekly']);
        $this->expressionFee($product, 'Loan Amount × Interest Rate% ÷ 10');

        $body = $this->formPreviewBody($product, 10000);
        unset($body['interest_rate']);
        $item = $this->postJson('/api/loans/preview', $body)->assertOk()->json('data.deductions.configured_fees.0');

        $this->assertEquals(30.0, $item['amount']);
        $this->assertSame('3.0000', $item['formula_inputs']['interest_rate']);
        $this->assertSame('weekly', $item['formula_inputs']['interest_rate_frequency']);
    }

    public function test_a_fee_above_the_largest_amount_answers_422_not_500(): void
    {
        $product = $this->freeProduct(['max_amount' => 10000000]);
        $this->expressionFee($product, 'Principal Amount × Principal Amount × Principal Amount', ['name' => 'Cube']);
        $loan = $this->approvedLoan($product, 1000000);

        $message = 'The fee "Cube" works out too large for this loan (above ₱9,999,999,999.99).';

        $this->getJson("/api/loans/{$loan->id}/release-preview")->assertUnprocessable()->assertJsonPath('errors.fees', [$message]);
        $this->patchJson("/api/loans/{$loan->id}/release", [])->assertUnprocessable()->assertJsonPath('errors.fees', [$message]);
        $this->assertSame($message, $this->postJson('/api/loans/preview', $this->formPreviewBody($product, 1000000))->assertOk()->json('data.deductions.error'));

        $this->postJson('/api/fees/formula-check', ['formula_expression' => 'Principal Amount × Principal Amount', 'principal_amount' => '9999999999.99'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.sample', ['On the sample loan the formula works out too large (above ₱9,999,999,999.99).']);
    }

    public function test_a_huge_typed_term_on_the_form_preview_is_reported_not_a_500(): void
    {
        $product = $this->freeProduct();
        $this->expressionFee($product, 'Loan Amount × Loan Term', ['name' => 'Per Month']);

        $body = [...$this->formPreviewBody($product, 10000), 'term' => 1000000000000];

        $this->assertSame(
            'The fee "Per Month" works out too large for this loan (above ₱9,999,999,999.99).',
            $this->postJson('/api/loans/preview', $body)->assertOk()->json('data.deductions.error'),
        );
    }

    public function test_a_negative_result_answers_422_naming_the_fee(): void
    {
        $product = $this->freeProduct();
        $this->expressionFee($product, 'Principal Amount * 1% - 2000', ['name' => 'Rebate']);
        $loan = $this->approvedLoan($product, 10000);

        $message = 'The fee "Rebate" works out below ₱0.00 for this loan.';

        $this->getJson("/api/loans/{$loan->id}/release-preview")->assertUnprocessable()->assertJsonPath('errors.fees', [$message]);
        $this->assertSame($message, $this->postJson('/api/loans/preview', $this->formPreviewBody($product, 10000))->assertOk()->json('data.deductions.error'));
        $this->getJson("/api/loans/{$loan->id}/disclosure")->assertUnprocessable()->assertJsonPath('errors.fees', [$message]);
        $this->patchJson("/api/loans/{$loan->id}/release", [])->assertUnprocessable()->assertJsonPath('errors.fees', [$message]);
        $this->assertSame('approved', $loan->fresh()->status);
    }

    // ── POST /fees/formula-check ─────────────────────────────────────────

    public function test_the_check_answers_the_sample_loans_figures(): void
    {
        $start = Carbon::today();
        $days = (string) (int) $start->diffInDays(LoanTermSchedule::maturityDate($start, 12, 'months', 'monthly'));
        $logs = AuditLog::count();
        $fees = Fee::count();

        $this->postJson('/api/fees/formula-check', ['formula_expression' => self::CONTRACT_EXAMPLE])
            ->assertOk()
            ->assertExactJson(['data' => [
                'valid' => true,
                'expression' => self::CONTRACT_EXAMPLE,
                'variables' => ['Principal Amount'],
                'inputs' => [
                    'principal_amount' => '100000.00',
                    'loan_amount' => '100000.00',
                    'interest_rate' => '3.0000',
                    'interest_rate_frequency' => 'monthly',
                    'loan_term' => '12',
                    'term_in_days' => $days,
                ],
                'raw_amount' => '6.849315',
                'rounded_amount' => '6.85',
                'amount' => '6.85',
                'clamped' => null,
            ]]);

        $this->assertSame($logs, AuditLog::count());
        $this->assertSame($fees, Fee::count());
    }

    public function test_the_check_takes_a_sample_and_bounds(): void
    {
        $this->postJson('/api/fees/formula-check', [
            'formula_expression' => 'Loan Amount ÷ Term in Days × 3%',
            'principal_amount' => '10000.00',
            'interest_rate' => '2.5',
            'interest_rate_frequency' => 'daily',
            'term' => 30,
            'term_unit' => 'days',
            'min_amount' => '12.50',
        ])->assertOk()
            ->assertJsonPath('data.variables', ['Loan Amount', 'Term in Days'])
            ->assertJsonPath('data.inputs.term_in_days', '30')
            ->assertJsonPath('data.inputs.loan_term', '1')
            ->assertJsonPath('data.inputs.interest_rate', '2.5000')
            ->assertJsonPath('data.inputs.interest_rate_frequency', 'daily')
            ->assertJsonPath('data.rounded_amount', '10.00')
            ->assertJsonPath('data.amount', '12.50')
            ->assertJsonPath('data.clamped', 'min');
    }

    public function test_the_check_refuses_a_bad_formula_or_sample_in_words(): void
    {
        $this->postJson('/api/fees/formula-check', ['formula_expression' => 'Principle * 2%'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.formula_expression', ["Use a variable name from the list: Principal Amount, Loan Amount, Interest Rate, Loan Term or Term in Days. 'Principle' isn't one."]);

        $this->postJson('/api/fees/formula-check', [])
            ->assertUnprocessable()->assertJsonValidationErrors('formula_expression');

        $this->postJson('/api/fees/formula-check', ['formula_expression' => 'Loan Amount / (Loan Term - 12)'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.sample', ['On the sample loan the formula divides by zero.']);

        $this->postJson('/api/fees/formula-check', ['formula_expression' => 'Loan Amount * 1% - 5000'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.sample', ['On the sample loan the formula works out below ₱0.00.']);
    }

    public function test_the_check_needs_fees_create_or_update(): void
    {
        $officer = $this->officer();

        $this->actingAs($officer)->postJson('/api/fees/formula-check', ['formula_expression' => self::CONTRACT_EXAMPLE])->assertForbidden();

        $officer->givePermissionTo('fees:update');
        $this->actingAs($officer->fresh())->postJson('/api/fees/formula-check', ['formula_expression' => self::CONTRACT_EXAMPLE])->assertOk();
    }

    // ── Audit trail and rollback ─────────────────────────────────────────

    public function test_the_audit_trail_reads_an_expression_fee_as_its_formula(): void
    {
        $row = AuditLog::create([
            'action' => 'updated',
            'auditable_type' => Fee::class,
            'auditable_id' => 1,
            'old_values' => ['id' => 1, 'type' => 'formula', 'value' => '2.5000', 'formula_expression' => null],
            'new_values' => ['value' => 0, 'formula_expression' => self::CONTRACT_EXAMPLE],
        ]);

        $changes = collect($this->getJson("/api/audit-logs/{$row->id}")->assertOk()->json('data.changes'))->keyBy('field');

        $this->assertSame(['field' => 'value', 'old' => '2.5%', 'new' => 'Formula: '.self::CONTRACT_EXAMPLE], $changes['value']);
        $this->assertSame(['field' => 'formula_expression', 'old' => null, 'new' => self::CONTRACT_EXAMPLE], $changes['formula_expression']);
    }

    public function test_the_rollback_is_refused_while_a_fee_has_an_expression(): void
    {
        $this->expressionFee($this->freeProduct(), 'Loan Amount * 1%');
        $migration = require database_path('migrations/2026_11_12_100000_add_formula_expression_to_fees_table.php');

        try {
            $migration->down();
            $this->fail('The rollback ran with an expression fee in Settings.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('written as a formula', $e->getMessage());
        }

        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('fees', 'formula_expression'));

        // Running it again changes nothing.
        $migration->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('fees', 'formula_expression'));
    }
}
