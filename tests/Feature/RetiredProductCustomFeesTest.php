<?php

namespace Tests\Feature;

use App\Models\LoanProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A loan product's `custom_fees` ("Additional fees") are retired: no release,
 * preview, disclosure or journal ever charged them, and fees are set up in
 * Settings → Fees, scoped to the product. A save that would change the stored
 * list is refused (422 on `custom_fees`); an absent value, the unchanged list
 * or `[]` is accepted, and the product still returns what it holds.
 * `loan-products:retired-custom-fees-report` lists the products still holding
 * some, and writes nothing.
 */
class RetiredProductCustomFeesTest extends TestCase
{
    use SetupLendyPH;

    private const RETIRED = 'Additional fees are retired. Set fees up in Settings → Fees, scoped to this product.';

    private const COMMAND = 'loan-products:retired-custom-fees-report';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_creating_a_product_with_custom_fees_is_refused(): void
    {
        $count = LoanProduct::count();

        $this->postJson('/api/loan-products', $this->payload(['custom_fees' => [['name' => 'Handling', 'type' => 'fixed', 'value' => 100]]]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.custom_fees.0', self::RETIRED);

        $this->assertSame($count, LoanProduct::count());
    }

    public function test_creating_a_product_with_no_custom_fees_or_an_empty_list_is_accepted(): void
    {
        $this->postJson('/api/loan-products', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.custom_fees', []);

        $this->postJson('/api/loan-products', $this->payload(['name' => 'Empty Fees', 'custom_fees' => []]))
            ->assertCreated()
            ->assertJsonPath('data.custom_fees', []);
    }

    public function test_changing_a_products_stored_custom_fees_is_refused_and_nothing_is_saved(): void
    {
        $stored = [['name' => 'Handling', 'type' => 'fixed', 'value' => 100]];
        $product = $this->productWithFees($stored);

        foreach ([
            [['name' => 'Handling', 'type' => 'fixed', 'value' => 150]],
            [['name' => 'Handling', 'type' => 'fixed', 'value' => 100], ['name' => 'Extra', 'type' => 'percentage', 'value' => 1]],
            [['name' => 'Handling', 'type' => 'percentage', 'value' => 100]],
        ] as $changed) {
            $this->putJson("/api/loan-products/{$product->id}", ['interest_rate' => 4, 'custom_fees' => $changed])
                ->assertUnprocessable()
                ->assertJsonPath('errors.custom_fees.0', self::RETIRED);
        }

        $product->refresh();
        $this->assertSame($stored, $product->custom_fees);
        $this->assertEqualsWithDelta(3, (float) $product->interest_rate, 0.0001);
    }

    public function test_adding_custom_fees_to_a_product_that_holds_none_is_refused(): void
    {
        $product = $this->productWithFees(null);

        $this->putJson("/api/loan-products/{$product->id}", ['custom_fees' => [['name' => 'Handling', 'type' => 'fixed', 'value' => 100]]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.custom_fees.0', self::RETIRED);

        $this->assertNull($product->fresh()->custom_fees);
    }

    public function test_resending_the_stored_custom_fees_unchanged_is_accepted_and_keeps_them(): void
    {
        $stored = [
            ['name' => 'Handling', 'type' => 'fixed', 'value' => 100.5, 'conditions' => ['term_days_gt' => 30]],
            ['name' => 'Rate', 'type' => 'formula', 'value' => 0.5, 'per_month' => true, 'min_amount' => 100],
        ];
        $product = $this->productWithFees($stored);

        // The same list as the product returns it, with keys in another order
        // and numbers as strings, still counts as unchanged.
        $resent = [
            ['type' => 'fixed', 'name' => 'Handling', 'conditions' => ['term_days_gt' => '30'], 'value' => '100.50'],
            ['name' => 'Rate', 'value' => '0.5', 'type' => 'formula', 'min_amount' => 100, 'per_month' => true],
        ];

        $this->putJson("/api/loan-products/{$product->id}", ['interest_rate' => 4, 'custom_fees' => $resent])
            ->assertOk()
            ->assertJsonPath('data.custom_fees', $stored);

        $product->refresh();
        $this->assertSame($stored, $product->custom_fees);
        $this->assertEqualsWithDelta(4, (float) $product->interest_rate, 0.0001);
    }

    public function test_saving_without_custom_fees_keeps_the_stored_ones_and_the_product_still_returns_them(): void
    {
        $stored = [['name' => 'Handling', 'type' => 'fixed', 'value' => 100]];
        $product = $this->productWithFees($stored);

        $this->putJson("/api/loan-products/{$product->id}", ['interest_rate' => 4])
            ->assertOk()
            ->assertJsonPath('data.custom_fees', $stored);

        $this->getJson("/api/loan-products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.custom_fees', $stored);
    }

    public function test_an_empty_list_is_accepted(): void
    {
        $product = $this->productWithFees([['name' => 'Handling', 'type' => 'fixed', 'value' => 100]]);

        $this->putJson("/api/loan-products/{$product->id}", ['custom_fees' => []])
            ->assertOk()
            ->assertJsonPath('data.custom_fees', []);
    }

    public function test_the_report_lists_each_product_holding_custom_fees(): void
    {
        $fixed = $this->productWithFees([
            ['name' => 'Handling', 'type' => 'fixed', 'value' => 1500, 'conditions' => ['term_days_gt' => 30, 'loan_amount_lt' => 50000]],
            ['name' => 'Rush', 'type' => 'percentage', 'value' => 0.3333],
        ], 'Salary Loan');
        $formula = $this->productWithFees([
            ['name' => 'Monthly Rate', 'type' => 'formula', 'value' => 0.5, 'per_month' => true, 'min_amount' => 100, 'max_amount' => 250],
        ], 'Business Loan');
        $none = $this->productWithFees(null, 'Plain Loan');
        $empty = $this->productWithFees([], 'Emptied Loan');

        $output = $this->runCommand();

        $this->assertMatchesRegularExpression("/\\|\\s*{$fixed->id}\\s*\\|\\s*Salary Loan\\s*\\|\\s*Handling\\s*\\|\\s*fixed\\s*\\|\\s*₱1,500\\.00\\s*\\|\\s*term over 30 days, loan amount under ₱50,000\\.00\\s*\\|/u", $output);
        $this->assertMatchesRegularExpression("/\\|\\s*{$fixed->id}\\s*\\|\\s*Salary Loan\\s*\\|\\s*Rush\\s*\\|\\s*percentage\\s*\\|\\s*0\\.3333%\\s*\\|\\s*—\\s*\\|/u", $output);
        $this->assertMatchesRegularExpression("/\\|\\s*{$formula->id}\\s*\\|\\s*Business Loan\\s*\\|\\s*Monthly Rate\\s*\\|\\s*formula\\s*\\|\\s*0\\.5% per month, min ₱100\\.00, max ₱250\\.00\\s*\\|/u", $output);
        $this->assertStringNotContainsString('Plain Loan', $output);
        $this->assertStringNotContainsString('Emptied Loan', $output);
        $this->assertMatchesRegularExpression('/Products holding retired custom fees:\s+2 products, 3 fees/', $output);
        $this->assertNotNull($none->id);
        $this->assertNotNull($empty->id);
    }

    public function test_the_report_says_none_when_no_product_holds_custom_fees(): void
    {
        LoanProduct::query()->update(['custom_fees' => null]);

        $output = $this->runCommand();

        $this->assertStringContainsString('None.', $output);
        $this->assertMatchesRegularExpression('/Products holding retired custom fees:\s+0 products, 0 fees/', $output);
    }

    public function test_the_report_writes_nothing(): void
    {
        $this->productWithFees([['name' => 'Handling', 'type' => 'fixed', 'value' => 100]], 'Salary Loan');
        $before = DB::table('loan_products')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $audits = DB::table('audit_logs')->count();

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->runCommand();

        $writes = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $sql) === 1,
        ));

        $this->assertNotSame([], $statements, 'The command read nothing, so the check proves nothing.');
        $this->assertSame([], $writes);
        $this->assertSame($before, DB::table('loan_products')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($audits, DB::table('audit_logs')->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Retired Fees Product',
            'interest_rate' => 3,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'processing_fee' => 0,
            'service_fee' => 0,
        ], $overrides);
    }

    /**
     * Written straight to the row, as products saved before the retirement hold them.
     *
     * @param  list<array<string, mixed>>|null  $customFees
     */
    private function productWithFees(?array $customFees, string $name = 'Retired Fees Product'): LoanProduct
    {
        return LoanProduct::factory()->create([
            'name' => $name,
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'term_unit' => 'months',
            'frequency' => 'monthly',
            'processing_fee' => 0,
            'service_fee' => 0,
            'notarial_fee' => 0,
            'custom_fees' => $customFees,
        ]);
    }

    private function runCommand(): string
    {
        $exitCode = Artisan::call(self::COMMAND);

        $this->assertSame(Command::SUCCESS, $exitCode);

        return Artisan::output();
    }
}
