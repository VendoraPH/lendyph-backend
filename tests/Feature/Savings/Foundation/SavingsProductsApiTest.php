<?php

namespace Tests\Feature\Savings\Foundation;

use App\Exceptions\SavingsRecordIsAppendOnlyException;
use App\Models\AccountingAccountMapping;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsProductRate;
use App\Models\TimeDeposit;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Savings\RateBook;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Savings products and their append-only rate history (design §2): created
 * inactive, activation refused (422) with no chart or an unmapped role,
 * deletion refused once an account or a time deposit exists, rates only
 * appended and never dated in the past.
 */
class SavingsProductsApiTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function regular(array $overrides = []): array
    {
        return array_merge([
            'code' => 'RS-01',
            'name' => 'Regular Savings',
            'kind' => 'regular',
            'interest_crediting' => 'quarterly',
            'minimum_opening_balance' => '500.00',
            'maintaining_balance' => '100',
            'minimum_adb_for_interest' => '1000.50',
            'annual_interest_rate' => '1.2500',
        ], $overrides);
    }

    private function seedChart(string $template): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, $template);
    }

    public function test_a_product_is_created_inactive_with_its_first_rate_from_today(): void
    {
        $response = $this->postJson('/api/savings/products', $this->regular())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'RS-01')
            ->assertJsonPath('data.kind', 'regular')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.minimum_opening_balance', '500.00')
            ->assertJsonPath('data.maintaining_balance', '100.00')
            ->assertJsonPath('data.minimum_adb_for_interest', '1000.50')
            ->assertJsonPath('data.account_count', 0)
            ->assertJsonPath('data.can_delete', true)
            ->assertJsonPath('data.current_rates.0.term_days', null)
            ->assertJsonPath('data.current_rates.0.annual_rate', '1.2500')
            ->assertJsonPath('data.current_rates.0.effective_from', '2026-11-10');

        $product = SavingsProduct::query()->findOrFail($response->json('data.id'));
        $this->assertSame($this->admin->id, $product->created_by);
        $this->assertSame('1.2500', app(RateBook::class)->rateOn($product->id, null, '2026-11-10'));
    }

    public function test_activation_is_refused_without_a_chart(): void
    {
        $message = $this->postJson('/api/savings/products', $this->regular(['is_active' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('is_active')->json('errors.is_active.0');

        $this->assertStringContainsString('chart of accounts', $message);
        $this->assertSame(0, SavingsProduct::query()->count());
    }

    public function test_activation_is_refused_while_a_role_is_unmapped_and_names_it_in_words(): void
    {
        $this->seedChart('lending_company');
        $id = $this->postJson('/api/savings/products', $this->regular())->assertCreated()->json('data.id');

        $message = $this->putJson("/api/savings/products/{$id}", ['is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('is_active')->json('errors.is_active.0');

        $this->assertStringContainsString('Savings deposits', $message);
        $this->assertStringContainsString('Accounting → Settings → Default Accounts', $message);
        $this->assertFalse(SavingsProduct::query()->findOrFail($id)->is_active);
    }

    public function test_activation_succeeds_on_a_cooperative_chart(): void
    {
        $this->seedChart('cooperative');

        $this->postJson('/api/savings/products', $this->regular(['is_active' => true]))
            ->assertCreated()->assertJsonPath('data.is_active', true);
    }

    public function test_a_time_deposit_product_needs_the_time_deposits_role_too(): void
    {
        $this->seedChart('cooperative');
        $regular = $this->postJson('/api/savings/products', $this->regular())->json('data.id');
        AccountingAccountMapping::query()->where('role', 'time_deposits')->delete();

        $body = [
            'code' => 'TD-01',
            'name' => 'Time Deposit',
            'kind' => 'time_deposit',
            'term_options_days' => [90, 180, 360],
            'pretermination_product_id' => $regular,
            'annual_interest_rate' => '3.0000',
            'is_active' => true,
        ];

        $this->postJson('/api/savings/products', $body)
            ->assertUnprocessable()->assertJsonValidationErrors('is_active')
            ->assertJsonFragment(['The product can\'t be turned on yet: the Time deposits posting role has no account. Set it in Accounting → Settings → Default Accounts, or ask your system administrator to prepare the savings books.']);

        $this->postJson('/api/savings/products', [...$body, 'is_active' => false])
            ->assertCreated()
            ->assertJsonPath('data.term_options_days', [90, 180, 360])
            ->assertJsonCount(3, 'data.current_rates')
            ->assertJsonPath('data.current_rates.1.term_days', 180)
            ->assertJsonPath('data.current_rates.1.annual_rate', '3.0000');
    }

    public function test_time_deposit_fields_belong_to_time_deposit_products_only(): void
    {
        $this->postJson('/api/savings/products', $this->regular(['term_options_days' => [90]]))
            ->assertUnprocessable()->assertJsonValidationErrors('term_options_days');
        $this->postJson('/api/savings/products', $this->regular(['kind' => 'time_deposit']))
            ->assertUnprocessable()->assertJsonValidationErrors('term_options_days');

        $timeDeposit = SavingsProduct::factory()->timeDeposit()->create();
        $this->postJson('/api/savings/products', [
            'code' => 'TD-02', 'name' => 'TD', 'kind' => 'time_deposit', 'term_options_days' => [90],
            'pretermination_product_id' => $timeDeposit->id, 'annual_interest_rate' => '1',
        ])->assertUnprocessable()->assertJsonValidationErrors('pretermination_product_id');
    }

    public function test_codes_are_unique_and_the_rate_is_accepted_only_on_create(): void
    {
        $id = $this->postJson('/api/savings/products', $this->regular())->assertCreated()->json('data.id');

        $this->postJson('/api/savings/products', $this->regular(['name' => 'Again']))
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->putJson("/api/savings/products/{$id}", ['annual_interest_rate' => '2'])
            ->assertUnprocessable()->assertJsonValidationErrors('annual_interest_rate');
        $this->putJson("/api/savings/products/{$id}", ['name' => 'Passbook Savings', 'maintaining_balance' => '200'])
            ->assertOk()->assertJsonPath('data.name', 'Passbook Savings')->assertJsonPath('data.maintaining_balance', '200.00');
    }

    public function test_the_list_counts_accounts_and_says_what_can_be_deleted(): void
    {
        $used = SavingsProduct::factory()->create(['code' => 'A-USED']);
        SavingsAccount::factory()->count(2)->for($used, 'product')->create();
        SavingsProduct::factory()->create(['code' => 'B-FREE']);

        $this->getJson('/api/savings/products')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'A-USED')
            ->assertJsonPath('data.0.account_count', 2)
            ->assertJsonPath('data.0.can_delete', false)
            ->assertJsonPath('data.1.code', 'B-FREE')
            ->assertJsonPath('data.1.can_delete', true);
    }

    public function test_delete_is_refused_once_an_account_or_a_time_deposit_exists(): void
    {
        $withAccount = SavingsProduct::factory()->create();
        SavingsAccount::factory()->for($withAccount, 'product')->create();
        $withDeposit = SavingsProduct::factory()->timeDeposit()->create();
        TimeDeposit::factory()->for($withDeposit, 'product')->create();
        $free = SavingsProduct::factory()->create();
        SavingsProductRate::factory()->for($free, 'product')->create();

        $this->deleteJson("/api/savings/products/{$withAccount->id}")->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->deleteJson("/api/savings/products/{$withDeposit->id}")->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->deleteJson("/api/savings/products/{$free->id}")->assertOk();

        $this->assertNotNull(SavingsProduct::query()->find($withAccount->id));
        $this->assertNull(SavingsProduct::query()->find($free->id));
        $this->deleteJson('/api/savings/products/999999')->assertNotFound();
    }

    public function test_rates_are_appended_from_today_and_never_dated_in_the_past(): void
    {
        $product = SavingsProduct::factory()->create();
        SavingsProductRate::factory()->for($product, 'product')->create(['annual_rate' => '1.0000', 'effective_from' => '2026-01-01']);

        $this->postJson("/api/savings/products/{$product->id}/rates", ['annual_rate' => '1.5', 'effective_from' => '2026-11-09'])
            ->assertUnprocessable()->assertJsonValidationErrors('effective_from');
        $this->postJson("/api/savings/products/{$product->id}/rates", ['annual_rate' => '1.5', 'term_days' => 90, 'effective_from' => '2026-11-10'])
            ->assertUnprocessable()->assertJsonValidationErrors('term_days');
        $this->postJson("/api/savings/products/{$product->id}/rates", ['annual_rate' => '1.5', 'effective_from' => '2026-12-01'])
            ->assertCreated()->assertJsonPath('data.annual_rate', '1.5000');

        $this->getJson("/api/savings/products/{$product->id}/rates")
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.effective_from', '2026-12-01');

        $this->assertSame('1.0000', app(RateBook::class)->rateOn($product->id, null, '2026-11-30'));
        $this->assertSame('1.5000', app(RateBook::class)->rateOn($product->id, null, '2026-12-01'));

        $rateId = SavingsProductRate::query()->latest('id')->value('id');
        $this->putJson("/api/savings/products/{$product->id}/rates/{$rateId}", ['annual_rate' => '9'])->assertNotFound();
        $this->deleteJson("/api/savings/products/{$product->id}/rates/{$rateId}")->assertNotFound();
    }

    public function test_a_time_deposit_rate_needs_an_offered_term(): void
    {
        $product = SavingsProduct::factory()->timeDeposit()->create(['term_options_days' => [90, 180]]);

        $this->postJson("/api/savings/products/{$product->id}/rates", ['annual_rate' => '3', 'effective_from' => '2026-11-10'])
            ->assertUnprocessable()->assertJsonValidationErrors('term_days');
        $this->postJson("/api/savings/products/{$product->id}/rates", ['annual_rate' => '3', 'term_days' => 120, 'effective_from' => '2026-11-10'])
            ->assertUnprocessable()->assertJsonValidationErrors('term_days');
        $this->postJson("/api/savings/products/{$product->id}/rates", ['annual_rate' => '3', 'term_days' => 180, 'effective_from' => '2026-11-10'])
            ->assertCreated()->assertJsonPath('data.term_days', 180);
    }

    public function test_the_rate_book_splits_a_range_where_the_rate_changes(): void
    {
        $product = SavingsProduct::factory()->create();
        SavingsProductRate::factory()->for($product, 'product')->create(['annual_rate' => '1.0000', 'effective_from' => '2026-01-01']);
        SavingsProductRate::factory()->for($product, 'product')->create(['annual_rate' => '2.0000', 'effective_from' => '2026-02-15']);
        SavingsProductRate::factory()->for($product, 'product')->create(['annual_rate' => '2.5000', 'effective_from' => '2026-02-15']);

        $this->assertSame([
            ['from' => '2026-01-01', 'to' => '2026-02-14', 'rate' => '1.0000'],
            ['from' => '2026-02-15', 'to' => '2026-03-31', 'rate' => '2.5000'],
        ], app(RateBook::class)->segments($product->id, null, '2026-01-01', '2026-03-31'));

        $this->assertSame([
            ['from' => '2025-12-30', 'to' => '2025-12-31', 'rate' => '0.0000'],
            ['from' => '2026-01-01', 'to' => '2026-01-02', 'rate' => '1.0000'],
        ], app(RateBook::class)->segments($product->id, null, '2025-12-30', '2026-01-02'));
    }

    public function test_a_rate_row_can_never_be_changed(): void
    {
        $rate = SavingsProductRate::factory()->create();

        $this->expectException(SavingsRecordIsAppendOnlyException::class);
        $rate->update(['annual_rate' => '9.0000']);
    }
}
