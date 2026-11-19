<?php

namespace Tests\Feature\Phase6\Branches;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AuditLog;
use App\Services\Accounting\AccountingRolesBackfill;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE5, data fix 3: 1190 Interbranch Clearing and its posting role
 * `interbranch_clearing`, in both chart templates and, for a chart seeded
 * before them, through `accounting:backfill-posting-roles` and a migration
 * that runs it once. Name first, idempotent, one system audit row per change,
 * anything uncertain skipped and listed.
 */
class InterbranchClearingBackfillTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const MIGRATION = 'migrations/2026_11_20_300100_add_interbranch_clearing_account.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_both_templates_carry_1190_and_its_role(): void
    {
        foreach (ChartOfAccountsSeeder::TEMPLATES as $template) {
            $seeder = app(ChartOfAccountsSeeder::class);
            $row = collect($seeder->chartFor($template))->firstWhere('code', '1190');

            $this->assertSame(['code' => '1190', 'name' => 'Interbranch Clearing', 'type' => 'asset', 'parent' => '1000'], $row, $template);
            $this->assertSame('1190', $seeder->mappingFor($template)['interbranch_clearing'], $template);
        }

        $this->seedChartOfAccounts();
        $this->assertSame($this->account('1190'), AccountingAccountMapping::resolved()['interbranch_clearing']);
    }

    public function test_the_migration_adds_and_maps_1190_with_system_audit_rows_and_a_second_run_changes_nothing(): void
    {
        $this->chartWithout1190();

        $this->migration()->up();

        $account = AccountingAccount::where('code', '1190')->sole();
        $this->assertSame(['Interbranch Clearing', 'asset', 'debit', 'investing', '1000', false, false, null], [
            $account->name, $account->type, $account->normal_balance, $account->cash_flow_category, $account->parent?->code,
            (bool) $account->is_contra, (bool) $account->is_group, $account->cash_kind,
        ]);
        $this->assertSame($account->id, AccountingAccountMapping::resolved()['interbranch_clearing']);

        $rows = AuditLog::whereIn('action', [AccountingRolesBackfill::AUDIT_ACCOUNT_ADDED, AccountingRolesBackfill::AUDIT_ROLE_MAPPED])->orderBy('id')->get();
        $this->assertSame([
            'System change: added account 1190 Interbranch Clearing (asset) for money moved between branches, as approved in the Phase 6 plan on 2026-11-19.',
            'System change: role interbranch_clearing (money moved between branches) now posts to 1190 Interbranch Clearing, as approved in the Phase 6 plan on 2026-11-19.',
        ], $rows->pluck('description')->all());
        $this->assertSame([null, null], $rows->pluck('user_id')->all());

        $after = $this->snapshot();
        $this->migration()->up();
        $this->artisan('accounting:backfill-posting-roles')->assertSuccessful();
        $this->assertSame($after, $this->snapshot());
    }

    public function test_a_dry_run_lists_the_change_and_writes_nothing(): void
    {
        $this->chartWithout1190();
        $before = $this->snapshot();

        $this->artisan('accounting:backfill-posting-roles', ['--dry-run' => true])
            ->expectsOutputToContain('Would add 1190 Interbranch Clearing (asset)')
            ->expectsOutputToContain('Would map interbranch_clearing to 1190 Interbranch Clearing')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_an_account_already_named_interbranch_clearing_is_mapped_rather_than_duplicated(): void
    {
        $this->chartWithout1190();
        $existing = AccountingAccount::create(['code' => '1195', 'name' => 'INTERBRANCH  clearing', 'type' => 'asset', 'is_active' => true]);

        $this->migration()->up();

        $this->assertFalse(AccountingAccount::where('code', '1190')->exists());
        $this->assertSame($existing->id, AccountingAccountMapping::resolved()['interbranch_clearing']);
    }

    public function test_anything_uncertain_is_skipped_and_listed(): void
    {
        $this->chartWithout1190();
        AccountingAccount::create(['code' => '1190', 'name' => 'Advances to Officers', 'type' => 'asset', 'is_active' => true]);
        $before = $this->snapshot();

        $this->artisan('accounting:backfill-posting-roles', ['--dry-run' => true])
            ->expectsOutputToContain('interbranch_clearing skipped: 1190 is already used by Advances to Officers')
            ->assertSuccessful();
        $this->migration()->up();

        $this->assertSame($before, $this->snapshot());
        $this->assertArrayNotHasKey('interbranch_clearing', AccountingAccountMapping::resolved());
    }

    public function test_two_namesakes_are_skipped(): void
    {
        $this->chartWithout1190();
        AccountingAccount::create(['code' => '1191', 'name' => 'Interbranch Clearing', 'type' => 'asset', 'is_active' => true]);
        AccountingAccount::create(['code' => '1192', 'name' => 'Interbranch-Clearing', 'type' => 'asset', 'is_active' => true]);

        $result = app(AccountingRolesBackfill::class)->run(dryRun: true);

        $this->assertContains(['role' => 'interbranch_clearing', 'reason' => 'more than one account is named Interbranch Clearing (1191, 1192)'], $result['skipped']);
    }

    public function test_the_migration_writes_exactly_what_the_service_writes(): void
    {
        $this->chartWithout1190();
        $before = $this->snapshot();

        DB::beginTransaction();
        app(AccountingRolesBackfill::class)->run(dryRun: false);
        $byService = $this->outcome();
        DB::rollBack();
        $this->assertSame($before, $this->snapshot());

        $this->migration()->up();

        $this->assertSame($byService, $this->outcome());
    }

    public function test_the_migration_calls_no_application_class_and_its_down_changes_nothing(): void
    {
        $source = file_get_contents(database_path(self::MIGRATION));
        $this->assertDoesNotMatchRegularExpression('/^use App\\\\/m', $source);
        $this->assertStringNotContainsString('App\\Services', $source);

        $this->chartWithout1190();
        $this->migration()->up();
        $after = $this->snapshot();
        $this->migration()->down();
        $this->assertSame($after, $this->snapshot());
    }

    public function test_without_a_chart_it_adds_nothing(): void
    {
        $this->migration()->up();

        $this->assertSame(0, AccountingAccount::count());
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }

    /** The default chart as a deployment that adopted accounting before Phase 6 has it. */
    private function chartWithout1190(): void
    {
        $this->seedChartOfAccounts();
        AccountingAccountMapping::query()->where('role', 'interbranch_clearing')->delete();
        AccountingAccount::query()->where('code', '1190')->delete();
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $rows = fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

        return ['accounts' => $rows('accounting_accounts'), 'mappings' => $rows('accounting_account_mappings'), 'audit' => $rows('audit_logs')];
    }

    /** @return array<string, mixed> */
    private function outcome(): array
    {
        $codes = DB::table('accounting_accounts')->pluck('code', 'id');

        return [
            'accounts' => DB::table('accounting_accounts')->orderBy('code')->get()->map(fn (object $row): array => [
                ...array_diff_key((array) $row, array_flip(['id', 'parent_id', 'created_at', 'updated_at'])),
                'parent' => $row->parent_id === null ? null : $codes[$row->parent_id],
            ])->all(),
            'mappings' => DB::table('accounting_account_mappings')->orderBy('role')->get()
                ->mapWithKeys(fn (object $row): array => [$row->role => $codes[$row->accounting_account_id]])->all(),
            'audit' => DB::table('audit_logs')->orderBy('id')->get()
                ->map(fn (object $row): array => array_diff_key((array) $row, array_flip(['id', 'auditable_id', 'created_at'])))->all(),
        ];
    }
}
