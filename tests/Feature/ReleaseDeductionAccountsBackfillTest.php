<?php

namespace Tests\Feature;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AuditLog;
use App\Services\Accounting\ReleaseDeductionAccountsBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The accounts and posting roles for release deductions (accountant-confirmed
 * 2026-10-03), added to charts seeded before they existed: the artisan command
 * that previews it, and the migration that runs it on deploy. Then the two
 * tax roles (decision 4b, 2026-11-02), which the same command previews and a
 * second migration runs: 2230 Documentary Stamp Tax Payable, a new account,
 * and 2220 Other Taxes Payable, an account every seeded chart already has.
 */
class ReleaseDeductionAccountsBackfillTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const MIGRATION = 'migrations/2026_10_03_130000_add_release_deduction_accounts.php';

    private const NEW_ROLES = [
        'service_fee_income',
        'notarial_fees_payable',
        'insurance_premium_payable',
        'share_capital',
        'other_fee_income',
    ];

    private const NEW_CODES = ['2030', '2040', '3060', '4080'];

    private const TAX_MIGRATION = 'migrations/2026_11_02_220500_add_tax_payable_accounts.php';

    private const TAX_ROLES = ['documentary_stamp_tax_payable', 'other_taxes_payable'];

    private const TAX_CODES = ['2230'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_without_a_chart_it_adds_nothing(): void
    {
        $this->migration()->up();

        $this->assertSame(0, AccountingAccount::count());
        $this->assertSame(0, AccountingAccountMapping::count());

        $this->artisan('accounting:backfill-release-deduction-accounts', ['--dry-run' => true])
            ->expectsOutputToContain('No chart of accounts')
            ->assertSuccessful();
    }

    public function test_it_adds_the_accounts_and_maps_the_roles_on_a_chart_seeded_before_them(): void
    {
        $this->chartSeededBeforeTheseAccounts();

        $this->migration()->up();

        $this->assertAccount('2030', 'Notarial Fees Payable', 'liability', '2000', 'financing');
        $this->assertAccount('2040', 'Insurance Premium Payable', 'liability', '2000', 'financing');
        $this->assertAccount('3060', 'Share Capital', 'equity', '3000', 'financing');
        $this->assertAccount('4080', 'Other Fee Income', 'income', '4000', 'operating');

        $this->assertSame([
            'service_fee_income' => '4040',
            'notarial_fees_payable' => '2030',
            'insurance_premium_payable' => '2040',
            'share_capital' => '3060',
            'other_fee_income' => '4080',
        ], $this->newMappings());

        $accountRows = AuditLog::where('action', ReleaseDeductionAccountsBackfill::AUDIT_ACCOUNT_ADDED)->get();
        $mappingRows = AuditLog::where('action', ReleaseDeductionAccountsBackfill::AUDIT_ROLE_MAPPED)->get();

        $this->assertCount(4, $accountRows);
        $this->assertCount(5, $mappingRows);

        foreach ([...$accountRows, ...$mappingRows] as $row) {
            $this->assertNull($row->user_id, 'a system change carries no user');
            $this->assertStringStartsWith('System change:', $row->description);
        }
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->chartSeededBeforeTheseAccounts();
        $this->migration()->up();
        $after = $this->snapshot();

        $this->migration()->up();
        $this->artisan('accounting:backfill-release-deduction-accounts')->assertSuccessful();

        $this->assertSame($after, $this->snapshot());
    }

    public function test_a_dry_run_lists_the_changes_and_writes_nothing(): void
    {
        $this->chartSeededBeforeTheseAccounts();
        $before = $this->snapshot();

        $this->artisan('accounting:backfill-release-deduction-accounts', ['--dry-run' => true])
            ->expectsOutputToContain('Would add 2030 Notarial Fees Payable (liability)')
            ->expectsOutputToContain('Would map service_fee_income to 4040 Service Fee Income')
            ->expectsOutputToContain('Would map share_capital to 3060 Share Capital')
            ->expectsOutputToContain('Would add 4 account(s) and map 5 role(s); 0 skipped.')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_it_maps_share_capital_to_the_cooperative_s_own_share_capital_account(): void
    {
        $this->chartSeededBeforeTheseAccounts();
        $own = $this->account3('3100', 'SHARE CAPITAL', 'equity');

        $this->migration()->up();

        $this->assertSame((int) $own->id, AccountingAccountMapping::resolved()['share_capital']);
        $this->assertFalse(AccountingAccount::where('code', '3060')->exists());
    }

    public function test_it_leaves_share_capital_unmapped_when_the_choice_is_not_certain(): void
    {
        $this->chartSeededBeforeTheseAccounts();
        $this->account3('3100', 'Paid-up Share Capital', 'equity');

        Log::spy();
        $this->migration()->up();

        $this->assertArrayNotHasKey('share_capital', AccountingAccountMapping::resolved());
        $this->assertFalse(AccountingAccount::where('code', '3060')->exists());
        // The rest still goes in.
        $this->assertSame('2030', $this->newMappings()['notarial_fees_payable']);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => array_column($context['rows'], 'role') === ['share_capital'],
        )->once();
    }

    public function test_it_leaves_share_capital_unmapped_when_two_accounts_have_that_name(): void
    {
        $this->chartSeededBeforeTheseAccounts();
        $this->account3('3100', 'Share Capital', 'equity');
        $this->account3('3110', 'Share-Capital', 'equity');

        $this->migration()->up();

        $this->assertArrayNotHasKey('share_capital', AccountingAccountMapping::resolved());
    }

    public function test_it_reuses_an_account_already_carrying_its_code_and_name_and_skips_one_that_does_not(): void
    {
        $this->chartSeededBeforeTheseAccounts();
        $same = $this->account3('2030', 'Notarial fees payable', 'liability');
        $this->account3('4080', 'Rental Income', 'income');

        $this->migration()->up();

        $this->assertSame((int) $same->id, AccountingAccountMapping::resolved()['notarial_fees_payable']);
        $this->assertArrayNotHasKey('other_fee_income', AccountingAccountMapping::resolved());
        $this->assertSame('Rental Income', AccountingAccount::where('code', '4080')->value('name'));
    }

    public function test_it_maps_to_an_account_already_carrying_the_name_under_another_code_instead_of_adding_a_second(): void
    {
        $this->chartSeededBeforeTheseAccounts();
        $own = $this->account3('2050', 'NOTARIAL FEES PAYABLE', 'liability');

        $this->migration()->up();

        $this->assertSame((int) $own->id, AccountingAccountMapping::resolved()['notarial_fees_payable']);
        $this->assertFalse(AccountingAccount::where('code', '2030')->exists());
        $this->assertSame(1, AccountingAccount::where('name', 'like', 'Notarial Fees Payable')->count());
    }

    public function test_it_skips_a_role_whose_name_is_on_an_account_it_cannot_post_to(): void
    {
        $this->chartSeededBeforeTheseAccounts();
        $this->account3('4090', 'Other Fee Income', 'income')->update(['is_active' => false]);

        $this->migration()->up();

        $this->assertArrayNotHasKey('other_fee_income', AccountingAccountMapping::resolved());
        $this->assertFalse(AccountingAccount::where('code', '4080')->exists());
    }

    public function test_it_skips_service_fee_income_when_4040_was_renamed_for_something_else(): void
    {
        $this->chartSeededBeforeTheseAccounts();
        AccountingAccount::where('code', '4040')->update(['name' => 'Late Fee Income']);

        $this->artisan('accounting:backfill-release-deduction-accounts', ['--dry-run' => true])
            ->expectsOutputToContain('service_fee_income skipped: 4040 is Late Fee Income, not an active, postable income account named Service Fee Income')
            ->assertSuccessful();

        $this->migration()->up();

        $this->assertArrayNotHasKey('service_fee_income', AccountingAccountMapping::resolved());
    }

    public function test_it_skips_service_fee_income_when_4040_is_not_a_postable_income_account(): void
    {
        $this->chartSeededBeforeTheseAccounts();
        AccountingAccount::where('code', '4040')->update(['is_active' => false]);

        $this->migration()->up();

        $this->assertArrayNotHasKey('service_fee_income', AccountingAccountMapping::resolved());
    }

    public function test_it_never_changes_a_mapping_an_administrator_already_set(): void
    {
        $this->chartSeededBeforeTheseAccounts();
        AccountingAccountMapping::create(['role' => 'other_fee_income', 'accounting_account_id' => $this->account('4070')]);

        $this->migration()->up();

        $this->assertSame($this->account('4070'), AccountingAccountMapping::resolved()['other_fee_income']);
        $this->assertFalse(AccountingAccount::where('code', '4080')->exists());
    }

    public function test_it_changes_no_posted_journal(): void
    {
        $this->chartSeededBeforeTheseAccounts();
        $this->postSimpleJournal('1010', '4030', 150_000);
        $journals = DB::table('accounting_journals')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        $lines = DB::table('accounting_journal_lines')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

        $this->migration()->up();

        $this->assertSame($journals, DB::table('accounting_journals')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
        $this->assertSame($lines, DB::table('accounting_journal_lines')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
    }

    public function test_the_migrations_write_exactly_what_the_service_writes(): void
    {
        $this->chartSeededBeforeTheseAccounts();
        $this->chartSeededBeforeTheTaxAccounts();
        // One role skipped (its code taken), one reused by name, one created.
        $this->account3('2040', 'Customer Deposits', 'liability');
        $this->account3('2050', 'Notarial Fees Payable', 'liability');
        $before = $this->snapshot();

        // The service's outcome, taken inside a savepoint and then undone.
        DB::beginTransaction();
        $result = app(ReleaseDeductionAccountsBackfill::class)->run(dryRun: false);
        $byService = $this->outcome();
        DB::rollBack();

        $this->assertSame($before, $this->snapshot());
        $this->assertCount(1, $result['skipped']);
        $this->assertContains(['role' => 'notarial_fees_payable', 'code' => '2050', 'name' => 'Notarial Fees Payable'], $result['mapped']);
        $this->assertNotContains('notarial_fees_payable', array_column($result['created'], 'role'));
        $this->assertContains(['role' => 'documentary_stamp_tax_payable', 'code' => '2230', 'name' => 'Documentary Stamp Tax Payable', 'type' => 'liability'], $result['created']);
        $this->assertContains(['role' => 'other_taxes_payable', 'code' => '2220', 'name' => 'Other Taxes Payable'], $result['mapped']);

        Log::spy();
        $this->migration()->up();
        $this->taxMigration()->up();

        $this->assertSame($byService, $this->outcome());

        // Each migration logs its own share of the service's result.
        $releasePart = $this->part($result, self::NEW_ROLES);
        $taxPart = $this->part($result, self::TAX_ROLES);

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => $message === ReleaseDeductionAccountsBackfill::summary($releasePart) && $context === $releasePart,
        )->once();
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => str_starts_with($message, 'tax payable accounts: 1 account(s) added, 2 role(s) mapped') && $context === $taxPart,
        )->once();
    }

    public function test_the_tax_migration_adds_2230_and_maps_both_tax_roles_on_a_chart_seeded_before_them(): void
    {
        $this->chartSeededBeforeTheTaxAccounts();

        $this->taxMigration()->up();

        $this->assertAccount('2230', 'Documentary Stamp Tax Payable', 'liability', '2200', 'financing');
        $this->assertSame(['documentary_stamp_tax_payable' => '2230', 'other_taxes_payable' => '2220'], $this->mappingsOf(self::TAX_ROLES));

        $accountRows = AuditLog::where('action', ReleaseDeductionAccountsBackfill::AUDIT_ACCOUNT_ADDED)->get();
        $mappingRows = AuditLog::where('action', ReleaseDeductionAccountsBackfill::AUDIT_ROLE_MAPPED)->get();

        $this->assertCount(1, $accountRows);
        $this->assertCount(2, $mappingRows);

        foreach ([...$accountRows, ...$mappingRows] as $row) {
            $this->assertNull($row->user_id, 'a system change carries no user');
            $this->assertStringStartsWith('System change:', $row->description);
            $this->assertStringContainsString('2026-11-02', $row->description);
        }
    }

    public function test_the_tax_migration_without_a_chart_adds_nothing_and_running_it_again_changes_nothing(): void
    {
        $this->taxMigration()->up();

        $this->assertSame(0, AccountingAccount::count());
        $this->assertSame(0, AccountingAccountMapping::count());

        $this->chartSeededBeforeTheTaxAccounts();
        $this->taxMigration()->up();
        $after = $this->snapshot();

        $this->taxMigration()->up();
        $this->artisan('accounting:backfill-release-deduction-accounts')->assertSuccessful();

        $this->assertSame($after, $this->snapshot());
    }

    public function test_a_dry_run_lists_the_tax_changes_and_writes_nothing(): void
    {
        $this->chartSeededBeforeTheTaxAccounts();
        $before = $this->snapshot();

        $this->artisan('accounting:backfill-release-deduction-accounts', ['--dry-run' => true])
            ->expectsOutputToContain('Would add 2230 Documentary Stamp Tax Payable (liability)')
            ->expectsOutputToContain('Would map documentary_stamp_tax_payable to 2230 Documentary Stamp Tax Payable')
            ->expectsOutputToContain('Would map other_taxes_payable to 2220 Other Taxes Payable')
            ->expectsOutputToContain('Would add 1 account(s) and map 2 role(s); 0 skipped.')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_the_tax_migration_skips_other_taxes_payable_when_2220_was_renamed_for_something_else(): void
    {
        $this->chartSeededBeforeTheTaxAccounts();
        AccountingAccount::where('code', '2220')->update(['name' => 'VAT Payable']);

        Log::spy();
        $this->taxMigration()->up();

        $this->assertArrayNotHasKey('other_taxes_payable', AccountingAccountMapping::resolved());
        $this->assertSame('2230', $this->mappingsOf(self::TAX_ROLES)['documentary_stamp_tax_payable']);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => array_column($context['rows'], 'role') === ['other_taxes_payable'],
        )->once();
    }

    public function test_the_tax_migration_never_changes_a_mapping_an_administrator_already_set(): void
    {
        $this->chartSeededBeforeTheTaxAccounts();
        AccountingAccountMapping::create(['role' => 'documentary_stamp_tax_payable', 'accounting_account_id' => $this->account('2220')]);

        $this->taxMigration()->up();

        $this->assertSame($this->account('2220'), AccountingAccountMapping::resolved()['documentary_stamp_tax_payable']);
        $this->assertFalse(AccountingAccount::where('code', '2230')->exists());
    }

    public function test_the_tax_migration_changes_no_posted_journal(): void
    {
        $this->chartSeededBeforeTheTaxAccounts();
        $this->postSimpleJournal('1010', '4080', 150_000);
        $journals = DB::table('accounting_journals')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        $lines = DB::table('accounting_journal_lines')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

        $this->taxMigration()->up();

        $this->assertSame($journals, DB::table('accounting_journals')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
        $this->assertSame($lines, DB::table('accounting_journal_lines')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
    }

    public function test_the_tax_migration_calls_no_application_class_and_its_down_changes_nothing(): void
    {
        $source = file_get_contents(database_path(self::TAX_MIGRATION));

        $this->assertDoesNotMatchRegularExpression('/^use App\\\\/m', $source);
        $this->assertStringNotContainsString('App\\Services', $source);

        $this->chartSeededBeforeTheTaxAccounts();
        $this->taxMigration()->up();
        $after = $this->snapshot();

        $this->taxMigration()->down();

        $this->assertSame($after, $this->snapshot());
    }

    public function test_the_migration_calls_no_application_class(): void
    {
        $source = file_get_contents(database_path(self::MIGRATION));

        $this->assertDoesNotMatchRegularExpression('/^use App\\\\/m', $source);
        $this->assertStringNotContainsString('App\\Services', $source);
    }

    public function test_the_migration_s_down_changes_nothing(): void
    {
        $this->chartSeededBeforeTheseAccounts();
        $this->migration()->up();
        $after = $this->snapshot();

        $this->migration()->down();

        $this->assertSame($after, $this->snapshot());
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }

    private function taxMigration(): Migration
    {
        return require database_path(self::TAX_MIGRATION);
    }

    /**
     * The default chart as a deployment that adopted accounting before
     * 2026-11-02 has it: no 2230, and neither tax role.
     */
    private function chartSeededBeforeTheTaxAccounts(): void
    {
        if (! AccountingAccount::query()->exists()) {
            $this->seedChartOfAccounts();
        }

        AccountingAccountMapping::query()->whereIn('role', self::TAX_ROLES)->delete();
        AccountingAccount::query()->whereIn('code', self::TAX_CODES)->delete();
    }

    /**
     * The service's result cut down to `$roles`, as the migration that
     * handles only those roles reports it.
     *
     * @param  array{chart: bool, created: list<array{role: string}>, mapped: list<array{role: string}>, kept: list<array{role: string}>, skipped: list<array{role: string}>}  $result
     * @param  list<string>  $roles
     * @return array{chart: bool, created: list<array{role: string}>, mapped: list<array{role: string}>, kept: list<array{role: string}>, skipped: list<array{role: string}>}
     */
    private function part(array $result, array $roles): array
    {
        $only = fn (array $rows): array => array_values(array_filter($rows, fn (array $row): bool => in_array($row['role'], $roles, true)));

        return [
            'chart' => $result['chart'],
            'created' => $only($result['created']),
            'mapped' => $only($result['mapped']),
            'kept' => $only($result['kept']),
            'skipped' => $only($result['skipped']),
        ];
    }

    /**
     * The default chart as a deployment that adopted accounting before
     * 2026-10-03 has it: none of the four accounts, none of the five roles.
     */
    private function chartSeededBeforeTheseAccounts(): void
    {
        $this->seedChartOfAccounts();

        AccountingAccountMapping::query()->whereIn('role', self::NEW_ROLES)->delete();
        AccountingAccount::query()->whereIn('code', self::NEW_CODES)->delete();
    }

    private function account3(string $code, string $name, string $type): AccountingAccount
    {
        return AccountingAccount::create([
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'is_active' => true,
        ]);
    }

    private function assertAccount(string $code, string $name, string $type, string $parent, string $cashFlowCategory): void
    {
        $account = AccountingAccount::where('code', $code)->firstOrFail();

        $this->assertSame($name, $account->name);
        $this->assertSame($type, $account->type);
        $this->assertSame('credit', $account->normal_balance);
        $this->assertSame($cashFlowCategory, $account->cash_flow_category);
        $this->assertSame($parent, $account->parent?->code);
        $this->assertTrue((bool) $account->is_active);
        $this->assertFalse((bool) $account->is_group);
        $this->assertFalse((bool) $account->is_contra);
        $this->assertNull($account->cash_kind);
    }

    /** @return array<string, string> role => account code, for the five new roles */
    private function newMappings(): array
    {
        return $this->mappingsOf(self::NEW_ROLES);
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, string> role => account code
     */
    private function mappingsOf(array $roles): array
    {
        $codes = AccountingAccount::query()->pluck('code', 'id');
        $mappings = [];

        foreach ($roles as $role) {
            $id = AccountingAccountMapping::resolved()[$role] ?? null;

            if ($id !== null) {
                $mappings[$role] = $codes[$id];
            }
        }

        return $mappings;
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $rows = fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

        return [
            'accounts' => $rows('accounting_accounts'),
            'mappings' => $rows('accounting_account_mappings'),
            'audit' => $rows('audit_logs'),
        ];
    }

    /**
     * What a run wrote, without the ids and times a rolled-back run cannot
     * share with a later one (InnoDB does not hand back auto-increment values).
     *
     * @return array<string, mixed>
     */
    private function outcome(): array
    {
        $codes = DB::table('accounting_accounts')->pluck('code', 'id');

        $accounts = DB::table('accounting_accounts')->orderBy('code')->get()
            ->map(fn (object $row): array => [
                ...array_diff_key((array) $row, array_flip(['id', 'parent_id', 'created_at', 'updated_at'])),
                'parent' => $row->parent_id === null ? null : $codes[$row->parent_id],
            ])->all();

        $mappings = DB::table('accounting_account_mappings')->orderBy('role')->get()
            ->mapWithKeys(fn (object $row): array => [$row->role => $codes[$row->accounting_account_id]])
            ->all();

        $audit = DB::table('audit_logs')->orderBy('id')->get()
            ->map(fn (object $row): array => array_diff_key((array) $row, array_flip(['id', 'auditable_id', 'created_at'])))
            ->all();

        return ['accounts' => $accounts, 'mappings' => $mappings, 'audit' => $audit];
    }
}
