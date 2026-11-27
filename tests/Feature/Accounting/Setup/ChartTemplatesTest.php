<?php

namespace Tests\Feature\Accounting\Setup;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Accounting\CashFlowCategories;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\PostingRules;
use InvalidArgumentException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The two chart templates POST /accounting/accounts/seed offers (Phase 2,
 * accounting round C, slice C1):
 *
 * - `lending_company`, today's chart and the default when no template is sent;
 * - `cooperative`, the same chart plus the rows below, with `net_surplus`
 *   posting to 3080 Undivided Net Surplus and the savings roles (Phase 3) to
 *   2050, 2060, 5160 and 2210. 3010–3030 stay, so every mapping keeps working
 *   (an admin can deactivate them).
 *
 * {@see self::COOPERATIVE_ADDITIONS} mirrors `COOPERATIVE_CHART_ADDITIONS` in
 * the frontend's `src/constants/chart-of-accounts.ts`, code for code and name
 * for name, as AccountingChartOfAccountsTest does for the lending chart.
 */
class ChartTemplatesTest extends TestCase
{
    use SetupLendyPH;

    /**
     * code => [name, type, parent code, is_group, is_contra, cash_kind].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: bool, 4: bool, 5: null}>
     */
    private const COOPERATIVE_ADDITIONS = [
        '2050' => ['Savings Deposits', 'liability', '2000', false, false, null],
        '2060' => ['Time Deposits', 'liability', '2000', false, false, null],
        '2070' => ['Interest on Share Capital Payable', 'liability', '2000', false, false, null],
        '2080' => ['Patronage Refund Payable', 'liability', '2000', false, false, null],
        '3070' => ['Statutory Funds', 'equity', '3000', true, false, null],
        '3071' => ['Reserve Fund', 'equity', '3070', false, false, null],
        '3072' => ['Education and Training Fund', 'equity', '3070', false, false, null],
        '3073' => ['Community Development Fund', 'equity', '3070', false, false, null],
        '3074' => ['Optional Fund', 'equity', '3070', false, false, null],
        '3080' => ['Undivided Net Surplus', 'equity', '3000', false, false, null],
        '5160' => ['Interest Expense on Deposits', 'expense', '5000', false, false, null],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_no_body_seeds_exactly_the_lending_chart_and_mapping(): void
    {
        $this->postJson('/api/accounting/accounts/seed')->assertCreated();

        $this->assertSame($this->fixtureOf(ChartOfAccountsSeeder::CHART), $this->storedChart());
        $this->assertSame(ChartOfAccountsSeeder::DEFAULT_MAPPING_CODES, $this->storedMapping());
        $this->assertSame('3030', $this->storedMapping()['net_surplus']);
    }

    public function test_the_lending_company_template_is_the_default_chart(): void
    {
        $this->postJson('/api/accounting/accounts/seed', ['template' => 'lending_company'])->assertCreated();

        $this->assertSame($this->fixtureOf(ChartOfAccountsSeeder::CHART), $this->storedChart());
        $this->assertSame(ChartOfAccountsSeeder::DEFAULT_MAPPING_CODES, $this->storedMapping());
    }

    public function test_a_null_template_is_the_default(): void
    {
        $this->postJson('/api/accounting/accounts/seed', ['template' => null])->assertCreated();

        $this->assertSame(count(ChartOfAccountsSeeder::CHART), AccountingAccount::query()->count());
    }

    public function test_the_cooperative_template_adds_its_rows_posts_net_surplus_to_3080_and_maps_the_savings_roles(): void
    {
        $response = $this->postJson('/api/accounting/accounts/seed', ['template' => 'cooperative'])->assertCreated();

        $expected = $this->fixtureOf(ChartOfAccountsSeeder::CHART) + self::COOPERATIVE_ADDITIONS;
        ksort($expected, SORT_STRING);

        $this->assertSame($expected, $this->storedChart());
        $this->assertCount(count(ChartOfAccountsSeeder::CHART) + count(self::COOPERATIVE_ADDITIONS), $response->json('data'));
        $this->assertSame(array_map('strval', array_keys($expected)), array_column($response->json('data'), 'code'));

        $mapping = $this->storedMapping();
        $this->assertSame('3080', $mapping['net_surplus']);
        $this->assertSame(
            array_merge(ChartOfAccountsSeeder::DEFAULT_MAPPING_CODES, ['net_surplus' => '3080']),
            $mapping,
        );

        $codes = AccountingAccount::query()->pluck('code', 'id');
        $this->assertSame(
            ['savings_deposits' => '2050', 'time_deposits' => '2060', 'interest_expense_deposits' => '5160', 'withholding_tax_deposits' => '2210'],
            collect(PostingRules::SAVINGS_ROLES)->mapWithKeys(fn (string $role) => [
                $role => $codes[AccountingAccountMapping::query()->where('role', $role)->value('accounting_account_id')] ?? null,
            ])->all(),
        );

        foreach (['3010', '3020', '3030'] as $code) {
            $this->assertTrue(AccountingAccount::query()->where('code', $code)->value('is_active'), "{$code} stays in the cooperative chart.");
        }
    }

    public function test_the_cooperative_rows_take_the_default_cash_flow_category(): void
    {
        $this->postJson('/api/accounting/accounts/seed', ['template' => 'cooperative'])->assertCreated();

        foreach (self::COOPERATIVE_ADDITIONS as $code => [, $type]) {
            $this->assertSame(
                CashFlowCategories::defaultFor($type, null),
                AccountingAccount::query()->where('code', $code)->value('cash_flow_category'),
                "{$code} takes the default cash flow category.",
            );
        }
    }

    public function test_the_net_surplus_account_is_postable_and_every_role_resolves(): void
    {
        $this->postJson('/api/accounting/accounts/seed', ['template' => 'cooperative'])->assertCreated();

        $mapping = $this->getJson('/api/accounting/settings/account-mapping')->assertOk()->json('data');
        $this->assertSame(AccountingAccountMapping::ROLES, array_keys($mapping));

        foreach ($mapping as $role => $accountId) {
            $this->assertTrue(AccountingAccount::query()->findOrFail($accountId)->isPostable(), "{$role} must resolve to a postable account.");
        }

        $this->assertFalse(AccountingAccount::query()->where('code', '3070')->sole()->isPostable(), '3070 Statutory Funds is a heading.');
    }

    public function test_the_seed_audit_row_names_the_template(): void
    {
        $this->postJson('/api/accounting/accounts/seed', ['template' => 'cooperative'])->assertCreated();

        $row = AuditLog::query()->where('action', 'chart_of_accounts_seeded')->sole();
        $this->assertSame('cooperative', $row->new_values['template']);
        $this->assertSame(count(ChartOfAccountsSeeder::CHART) + count(self::COOPERATIVE_ADDITIONS), $row->new_values['accounts']);
        $this->assertSame($this->admin->id, $row->user_id);
    }

    public function test_an_unknown_template_is_refused_on_template(): void
    {
        $this->postJson('/api/accounting/accounts/seed', ['template' => 'corporate'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('template');

        $this->postJson('/api/accounting/accounts/seed', ['template' => ['cooperative']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('template');

        $this->assertSame(0, AccountingAccount::query()->count());
    }

    public function test_seeding_needs_chart_of_accounts_create(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole('general_bookkeeper');
        $this->actingAs($user);

        $this->postJson('/api/accounting/accounts/seed', ['template' => 'cooperative'])->assertForbidden();
        $this->postJson('/api/accounting/accounts/seed', ['template' => 'corporate'])->assertForbidden();

        $this->assertSame(0, AccountingAccount::query()->count());
    }

    public function test_a_second_seed_is_refused_whatever_the_template(): void
    {
        $this->postJson('/api/accounting/accounts/seed', ['template' => 'cooperative'])->assertCreated();

        $this->postJson('/api/accounting/accounts/seed')->assertStatus(409);
        $this->postJson('/api/accounting/accounts/seed', ['template' => 'cooperative'])->assertStatus(409);

        $this->assertSame(count(ChartOfAccountsSeeder::CHART) + count(self::COOPERATIVE_ADDITIONS), AccountingAccount::query()->count());
    }

    public function test_the_service_refuses_an_unknown_template(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ChartOfAccountsSeeder::class)->seed(null, 'corporate');
    }

    public function test_chart_for_and_mapping_for_describe_each_template(): void
    {
        $seeder = app(ChartOfAccountsSeeder::class);

        $this->assertSame(ChartOfAccountsSeeder::CHART, $seeder->chartFor('lending_company'));
        $this->assertSame(ChartOfAccountsSeeder::DEFAULT_MAPPING_CODES, $seeder->mappingFor('lending_company'));
        $this->assertSame(array_map('strval', array_keys(self::COOPERATIVE_ADDITIONS)), array_column(ChartOfAccountsSeeder::COOPERATIVE_ADDITIONS, 'code'));
        $this->assertCount(count(ChartOfAccountsSeeder::CHART) + count(self::COOPERATIVE_ADDITIONS), $seeder->chartFor('cooperative'));
        $this->assertSame('3080', $seeder->mappingFor('cooperative')['net_surplus']);
        $this->assertSame(['lending_company', 'cooperative'], ChartOfAccountsSeeder::TEMPLATES);
    }

    // ── Helpers ──

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{0: string, 1: string, 2: string|null, 3: bool, 4: bool, 5: string|null}>
     */
    private function fixtureOf(array $rows): array
    {
        $fixture = [];

        foreach ($rows as $row) {
            $fixture[$row['code']] = [
                $row['name'],
                $row['type'],
                $row['parent'] ?? null,
                $row['is_group'] ?? false,
                $row['is_contra'] ?? false,
                $row['cash_kind'] ?? null,
            ];
        }

        ksort($fixture, SORT_STRING);

        return $fixture;
    }

    /** @return array<string, array{0: string, 1: string, 2: string|null, 3: bool, 4: bool, 5: string|null}> */
    private function storedChart(): array
    {
        $accounts = AccountingAccount::query()->inCodeOrder()->get();
        $codeById = $accounts->pluck('code', 'id');
        $chart = [];

        foreach ($accounts as $account) {
            $chart[$account->code] = [
                $account->name,
                $account->type,
                $account->parent_id === null ? null : $codeById[$account->parent_id],
                $account->is_group,
                $account->is_contra,
                $account->cash_kind,
            ];
        }

        return $chart;
    }

    /** @return array<string, string> role => account code, in ROLES order */
    private function storedMapping(): array
    {
        $codes = AccountingAccount::query()->pluck('code', 'id');
        $mapping = AccountingAccountMapping::query()->pluck('accounting_account_id', 'role');
        $stored = [];

        foreach (array_keys(ChartOfAccountsSeeder::DEFAULT_MAPPING_CODES) as $role) {
            $stored[$role] = $codes[$mapping[$role]] ?? null;
        }

        return $stored;
    }
}
