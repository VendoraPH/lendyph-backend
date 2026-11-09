<?php

namespace Tests\Feature\Ledger;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AuditLog;
use App\Services\Accounting\AccountingRolesBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The Phase 2 posting roles and their accounts, on a fresh chart and on a
 * chart seeded before them: the artisan command that previews the change
 * (`accounting:backfill-posting-roles --dry-run`) and the migration that runs
 * it on deploy.
 *
 * - `gcash_service_income` → 4090 GCash Service Income (new, income)
 * - `loan_adjustments` → 1180 Loan Adjustments Clearing (new, asset)
 * - `unearned_restructure_income` → 1210 Unearned Interest and Penalty –
 *   Restructured Loans (new, contra-asset under 1000, beside 1200)
 * - `net_surplus` → the existing 3030 Retained Earnings
 * - `suspense` → 2390 Suspense Account (new, liability)
 *
 * Add only, one system audit row per account and role added, safe to run
 * twice, and never a posted journal touched.
 */
class PostingRolesBackfillTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const MIGRATION = 'migrations/2026_11_08_100000_add_posting_engine_accounts.php';

    private const LOAN_COLUMNS_MIGRATION = 'migrations/2026_11_08_100100_add_release_method_and_restructure_split_to_loans_table.php';

    private const ROLES = ['gcash_service_income', 'loan_adjustments', 'unearned_restructure_income', 'net_surplus', 'suspense'];

    private const NEW_CODES = ['1180', '1210', '2390', '4090'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_fresh_chart_has_all_five_roles(): void
    {
        $this->seedChartOfAccounts();

        $this->assertSame([
            'gcash_service_income' => '4090',
            'loan_adjustments' => '1180',
            'unearned_restructure_income' => '1210',
            'net_surplus' => '3030',
            'suspense' => '2390',
        ], $this->mappingsOf(self::ROLES));

        $this->assertAccount('4090', 'GCash Service Income', 'income', '4000', false, 'credit', 'operating');
        // A fresh chart seeds the loan accounts as operating (Phase 2,
        // decision 9); the migration above adds them as investing and the
        // 2026_11_09_110000 migration then moves them.
        $this->assertAccount('1180', 'Loan Adjustments Clearing', 'asset', '1000', false, 'debit', 'operating');
        $this->assertAccount('1210', 'Unearned Interest and Penalty – Restructured Loans', 'asset', '1000', true, 'credit', 'operating');
        $this->assertAccount('2390', 'Suspense Account', 'liability', '2000', false, 'credit', 'financing');
    }

    public function test_without_a_chart_it_adds_nothing(): void
    {
        $this->migration()->up();

        $this->assertSame(0, AccountingAccount::count());
        $this->assertSame(0, AccountingAccountMapping::count());

        $this->artisan('accounting:backfill-posting-roles', ['--dry-run' => true])
            ->expectsOutputToContain('No chart of accounts')
            ->assertSuccessful();
    }

    public function test_it_adds_the_accounts_and_maps_the_roles_on_a_chart_seeded_before_them(): void
    {
        $this->chartSeededBeforeTheseRoles();

        $this->migration()->up();

        $this->assertAccount('4090', 'GCash Service Income', 'income', '4000', false, 'credit', 'operating');
        $this->assertAccount('1180', 'Loan Adjustments Clearing', 'asset', '1000', false, 'debit', 'investing');
        $this->assertAccount('1210', 'Unearned Interest and Penalty – Restructured Loans', 'asset', '1000', true, 'credit', 'investing');
        $this->assertAccount('2390', 'Suspense Account', 'liability', '2000', false, 'credit', 'financing');
        $this->assertSame([
            'gcash_service_income' => '4090',
            'loan_adjustments' => '1180',
            'unearned_restructure_income' => '1210',
            'net_surplus' => '3030',
            'suspense' => '2390',
        ], $this->mappingsOf(self::ROLES));

        $accountRows = AuditLog::where('action', AccountingRolesBackfill::AUDIT_ACCOUNT_ADDED)->get();
        $mappingRows = AuditLog::where('action', AccountingRolesBackfill::AUDIT_ROLE_MAPPED)->get();

        $this->assertCount(4, $accountRows);
        $this->assertCount(5, $mappingRows);

        foreach ([...$accountRows, ...$mappingRows] as $row) {
            $this->assertNull($row->user_id, 'a system change carries no user');
            $this->assertStringStartsWith('System change:', $row->description);
        }
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->chartSeededBeforeTheseRoles();
        $this->migration()->up();
        $after = $this->snapshot();

        $this->migration()->up();
        $this->artisan('accounting:backfill-posting-roles')->assertSuccessful();

        $this->assertSame($after, $this->snapshot());
    }

    public function test_a_dry_run_lists_the_changes_and_writes_nothing(): void
    {
        $this->chartSeededBeforeTheseRoles();
        $before = $this->snapshot();

        $this->artisan('accounting:backfill-posting-roles', ['--dry-run' => true])
            ->expectsOutputToContain('Would add 4090 GCash Service Income (income)')
            ->expectsOutputToContain('Would add 1210 Unearned Interest and Penalty – Restructured Loans (asset)')
            ->expectsOutputToContain('Would map net_surplus to 3030 Retained Earnings')
            ->expectsOutputToContain('Would map suspense to 2390 Suspense Account')
            ->expectsOutputToContain('Would add 4 account(s) and map 5 role(s); 0 skipped.')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_the_migration_writes_exactly_what_the_service_writes(): void
    {
        $this->chartSeededBeforeTheseRoles();
        // One role skipped (its code taken), one reused by name, the rest created.
        $this->account3('2390', 'Customer Deposits', 'liability');
        $this->account3('4095', 'GCASH SERVICE INCOME', 'income');
        $before = $this->snapshot();

        DB::beginTransaction();
        $result = app(AccountingRolesBackfill::class)->run(dryRun: false);
        $byService = $this->outcome();
        DB::rollBack();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(['suspense'], array_column($result['skipped'], 'role'));
        $this->assertContains(['role' => 'gcash_service_income', 'code' => '4095', 'name' => 'GCASH SERVICE INCOME'], $result['mapped']);

        Log::spy();
        $this->migration()->up();

        $this->assertSame($byService, $this->outcome());
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => $message === AccountingRolesBackfill::summary($result) && $context === $result,
        )->once();
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => array_column($context['rows'], 'role') === ['suspense'],
        )->once();
    }

    public function test_it_skips_net_surplus_when_3030_was_renamed_for_something_else(): void
    {
        $this->chartSeededBeforeTheseRoles();
        AccountingAccount::where('code', '3030')->update(['name' => 'Undivided Net Surplus']);

        $this->artisan('accounting:backfill-posting-roles', ['--dry-run' => true])
            ->expectsOutputToContain('net_surplus skipped: 3030 is Undivided Net Surplus')
            ->assertSuccessful();

        $this->migration()->up();

        $this->assertArrayNotHasKey('net_surplus', AccountingAccountMapping::resolved());
        $this->assertSame('1210', $this->mappingsOf(self::ROLES)['unearned_restructure_income']);
    }

    /** 1210 must be a contra asset; an ordinary asset with its name is not taken. */
    public function test_it_skips_unearned_income_when_its_namesake_is_not_a_contra_asset(): void
    {
        $this->chartSeededBeforeTheseRoles();
        $this->account3('1215', 'Unearned Interest and Penalty - Restructured Loans', 'asset');

        $this->migration()->up();

        $this->assertArrayNotHasKey('unearned_restructure_income', AccountingAccountMapping::resolved());
        $this->assertFalse(AccountingAccount::where('code', '1210')->exists());
    }

    public function test_it_never_changes_a_mapping_an_administrator_already_set(): void
    {
        $this->chartSeededBeforeTheseRoles();
        AccountingAccountMapping::create(['role' => 'gcash_service_income', 'accounting_account_id' => $this->account('4070')]);

        $this->migration()->up();

        $this->assertSame($this->account('4070'), AccountingAccountMapping::resolved()['gcash_service_income']);
        $this->assertFalse(AccountingAccount::where('code', '4090')->exists());
    }

    /** Journal lines (count, sums and highest id) are the same before and after each migration. */
    public function test_neither_migration_changes_a_posted_journal(): void
    {
        $this->chartSeededBeforeTheseRoles();
        $this->postSimpleJournal('1010', '4030', 150_000);
        $this->postSimpleJournal('5140', '1200', 40_000);
        $before = $this->journalFingerprint();

        $this->migration()->up();
        $this->assertSame($before, $this->journalFingerprint());

        $this->loanColumnsMigration()->up();
        $this->assertSame($before, $this->journalFingerprint());
    }

    public function test_the_migration_calls_no_application_class_and_its_down_changes_nothing(): void
    {
        foreach ([self::MIGRATION, self::LOAN_COLUMNS_MIGRATION] as $file) {
            $source = file_get_contents(database_path($file));

            $this->assertDoesNotMatchRegularExpression('/^use App\\\\/m', $source, $file);
            $this->assertStringNotContainsString('App\\Services', $source, $file);
        }

        $this->chartSeededBeforeTheseRoles();
        $this->migration()->up();
        $after = $this->snapshot();

        $this->migration()->down();

        $this->assertSame($after, $this->snapshot());
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }

    private function loanColumnsMigration(): Migration
    {
        return require database_path(self::LOAN_COLUMNS_MIGRATION);
    }

    /** The default chart as a deployment that adopted accounting before Phase 2 has it. */
    private function chartSeededBeforeTheseRoles(): void
    {
        $this->seedChartOfAccounts();

        AccountingAccountMapping::query()->whereIn('role', self::ROLES)->delete();
        AccountingAccount::query()->whereIn('code', self::NEW_CODES)->delete();
    }

    private function account3(string $code, string $name, string $type): AccountingAccount
    {
        return AccountingAccount::create(['code' => $code, 'name' => $name, 'type' => $type, 'is_active' => true]);
    }

    private function assertAccount(string $code, string $name, string $type, ?string $parent, bool $contra, string $normal, string $cashFlowCategory): void
    {
        $account = AccountingAccount::where('code', $code)->firstOrFail();

        $this->assertSame($name, $account->name);
        $this->assertSame($type, $account->type);
        $this->assertSame($normal, $account->normal_balance);
        $this->assertSame($cashFlowCategory, $account->cash_flow_category);
        $this->assertSame($parent, $account->parent?->code);
        $this->assertSame($contra, (bool) $account->is_contra);
        $this->assertTrue((bool) $account->is_active);
        $this->assertFalse((bool) $account->is_group);
        $this->assertNull($account->cash_kind);
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, string> role => account code
     */
    private function mappingsOf(array $roles): array
    {
        $codes = AccountingAccount::query()->pluck('code', 'id');
        $resolved = AccountingAccountMapping::resolved();
        $mappings = [];

        foreach ($roles as $role) {
            if (isset($resolved[$role])) {
                $mappings[$role] = $codes[$resolved[$role]];
            }
        }

        return $mappings;
    }

    /** @return array{count: int, debit: int, credit: int, max_id: int, journals: list<array<string, mixed>>} */
    private function journalFingerprint(): array
    {
        $lines = DB::table('accounting_journal_lines');

        return [
            'count' => (clone $lines)->count(),
            'debit' => (int) (clone $lines)->sum('debit'),
            'credit' => (int) (clone $lines)->sum('credit'),
            'max_id' => (int) (clone $lines)->max('id'),
            'journals' => DB::table('accounting_journals')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
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
     * share with a later one.
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
