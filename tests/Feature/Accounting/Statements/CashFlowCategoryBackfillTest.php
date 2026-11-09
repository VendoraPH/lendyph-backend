<?php

namespace Tests\Feature\Accounting\Statements;

use App\Models\AccountingAccount;
use App\Models\AuditLog;
use App\Services\Accounting\CashFlowCategoryBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Existing charts move their loan accounts to operating (decision 9), only
 * where the category is still `investing` and no user ever changed it, with a
 * system audit row per account and a dry run that writes nothing.
 */
class CashFlowCategoryBackfillTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const MIGRATION = 'migrations/2026_11_09_110000_move_loan_accounts_to_operating_cash_flow.php';

    private const LOAN_CODES = ['1110', '1120', '1150', '1160', '1180', '1200', '1210'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** A chart as it was seeded before this change: every loan account investing, with no audit row. */
    private function seedAnOldChart(): void
    {
        $this->seedChartOfAccounts();
        DB::table('accounting_accounts')->whereIn('code', self::LOAN_CODES)->update(['cash_flow_category' => 'investing']);
    }

    private function categories(): array
    {
        return DB::table('accounting_accounts')->whereIn('code', self::LOAN_CODES)->orderBy('code')->pluck('cash_flow_category', 'code')->all();
    }

    private function systemRows(): array
    {
        return AuditLog::query()
            ->where('action', CashFlowCategoryBackfill::AUDIT_ACTION)
            ->orderBy('id')
            ->get(['user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'description'])
            ->map(fn (AuditLog $row): array => $row->only(['user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'description']))
            ->all();
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }

    public function test_a_new_chart_seeds_its_loan_accounts_as_operating(): void
    {
        $this->seedChartOfAccounts();

        $this->assertSame(array_fill_keys(self::LOAN_CODES, 'operating'), $this->categories());
        $this->assertSame('investing', DB::table('accounting_accounts')->where('code', '1410')->value('cash_flow_category'));
    }

    public function test_it_moves_untouched_investing_loan_accounts_with_one_system_audit_row_each(): void
    {
        $this->seedAnOldChart();

        $this->migration()->up();

        $this->assertSame(array_fill_keys(self::LOAN_CODES, 'operating'), $this->categories());
        $rows = $this->systemRows();
        $this->assertCount(7, $rows);
        $this->assertSame([null], array_values(array_unique(array_column($rows, 'user_id'))));
        $this->assertSame(AccountingAccount::class, $rows[0]['auditable_type']);
        $this->assertSame(['cash_flow_category' => 'investing'], $rows[0]['old_values']);
        $this->assertSame(['cash_flow_category' => 'operating'], $rows[0]['new_values']);
        $this->assertStringStartsWith('System change: 1110 Current Loans Receivable', $rows[0]['description']);
        $this->assertSame('investing', DB::table('accounting_accounts')->where('code', '1410')->value('cash_flow_category'), 'other assets stay');
    }

    public function test_a_users_change_is_kept_and_the_skip_is_reported(): void
    {
        $this->seedAnOldChart();
        $id = $this->account('1120');
        $this->putJson("/api/accounting/accounts/{$id}", ['cash_flow_category' => 'financing'])->assertOk();
        $this->putJson("/api/accounting/accounts/{$id}", ['cash_flow_category' => 'investing'])->assertOk();

        $result = app(CashFlowCategoryBackfill::class)->run(false);

        $this->assertSame('investing', $this->categories()['1120']);
        $this->assertSame(['1110', '1150', '1160', '1180', '1200', '1210'], array_column($result['moved'], 'code'));
        $this->assertSame([['code' => '1120', 'name' => 'Past Due Loans Receivable', 'reason' => 'a user set its cash flow category, so their choice is kept']], $result['skipped']);
        $this->assertCount(6, $this->systemRows());
    }

    public function test_an_account_no_longer_investing_is_left_alone(): void
    {
        $this->seedAnOldChart();
        DB::table('accounting_accounts')->where('code', '1150')->update(['cash_flow_category' => 'financing']);

        $result = app(CashFlowCategoryBackfill::class)->run(false);

        $this->assertSame('financing', $this->categories()['1150']);
        $this->assertSame([['code' => '1150', 'name' => 'Interest Receivable', 'reason' => 'it reads financing, not investing']], $result['skipped']);
    }

    public function test_a_dry_run_lists_the_moves_and_writes_nothing(): void
    {
        $this->seedAnOldChart();
        $audits = AuditLog::count();

        $result = app(CashFlowCategoryBackfill::class)->run(true);

        $this->assertSame(self::LOAN_CODES, array_column($result['moved'], 'code'));
        $this->assertSame(array_fill_keys(self::LOAN_CODES, 'investing'), $this->categories());
        $this->assertSame($audits, AuditLog::count());

        $this->artisan('accounting:cash-flow-loans-operating', ['--dry-run' => true])
            ->expectsOutputToContain('Would move 1110 Current Loans Receivable to operating')
            ->expectsOutputToContain('Would move 7 account(s)')
            ->assertSuccessful();
        $this->assertSame(array_fill_keys(self::LOAN_CODES, 'investing'), $this->categories());
        $this->assertSame($audits, AuditLog::count());
    }

    public function test_a_second_run_writes_nothing(): void
    {
        $this->seedAnOldChart();
        $this->migration()->up();
        $audits = AuditLog::count();

        $this->migration()->up();
        $result = app(CashFlowCategoryBackfill::class)->run(false);
        $this->artisan('accounting:cash-flow-loans-operating')->expectsOutputToContain('Moved 0 account(s)')->assertSuccessful();

        $this->assertSame([], $result['moved']);
        $this->assertSame(self::LOAN_CODES, array_column($result['kept'], 'code'));
        $this->assertSame($audits, AuditLog::count());
    }

    public function test_without_a_chart_it_writes_nothing(): void
    {
        $audits = AuditLog::count();

        $this->migration()->up();
        $result = app(CashFlowCategoryBackfill::class)->run(false);

        $this->assertFalse($result['chart']);
        $this->assertSame($audits, AuditLog::count());
        $this->artisan('accounting:cash-flow-loans-operating')->expectsOutputToContain('No chart of accounts')->assertSuccessful();
    }

    public function test_the_migration_writes_exactly_what_the_service_writes(): void
    {
        $this->seedAnOldChart();
        $id = $this->account('1120');
        $this->putJson("/api/accounting/accounts/{$id}", ['cash_flow_category' => 'financing'])->assertOk();
        $this->putJson("/api/accounting/accounts/{$id}", ['cash_flow_category' => 'investing'])->assertOk();

        DB::beginTransaction();
        app(CashFlowCategoryBackfill::class)->run(false);
        $byService = [$this->categories(), $this->systemRows()];
        DB::rollBack();

        $this->assertSame([], $this->systemRows());
        $this->migration()->up();

        $this->assertSame($byService, [$this->categories(), $this->systemRows()]);
    }

    public function test_it_changes_no_journal_and_its_migration_uses_no_application_class(): void
    {
        $this->seedAnOldChart();
        $this->postSimpleJournal('1110', '1010', 100000);
        $before = DB::table('accounting_journal_lines')->selectRaw('count(*) c, sum(debit) d, sum(credit) cr, max(id) m')->first();

        $this->migration()->up();
        $this->migration()->down();

        $this->assertEquals($before, DB::table('accounting_journal_lines')->selectRaw('count(*) c, sum(debit) d, sum(credit) cr, max(id) m')->first());
        $this->assertDoesNotMatchRegularExpression('/^use App\\\\/m', file_get_contents(database_path(self::MIGRATION)));
        $this->assertSame(array_fill_keys(self::LOAN_CODES, 'operating'), $this->categories(), 'down() undoes nothing');
    }
}
