<?php

namespace Tests\Feature\Savings\Foundation;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AuditLog;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Savings\SavingsBooks;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `savings:prepare-books [--dry-run]` and the roles migration (design §4):
 * add any missing 2050, 2060 and 5160 and map the four savings roles,
 * idempotent and audited as system changes. No chart, nothing written. The
 * migration only touches a chart that already has 2050.
 */
class PrepareBooksTest extends TestCase
{
    use SetupLendyPH;

    private const MIGRATION = 'database/migrations/2026_11_10_500200_add_savings_posting_roles.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function snapshot(): array
    {
        return [
            AccountingAccount::query()->count(),
            AccountingAccountMapping::query()->count(),
            AuditLog::query()->count(),
        ];
    }

    /** @return array<string, string> role => account code */
    private function savingsMapping(): array
    {
        return AccountingAccountMapping::query()
            ->whereIn('role', SavingsBooks::ROLES)
            ->with('account')
            ->get()
            ->mapWithKeys(fn (AccountingAccountMapping $m) => [$m->role => $m->account->code])
            ->sortKeys()
            ->all();
    }

    public function test_no_chart_means_nothing_is_written(): void
    {
        $before = $this->snapshot();

        $this->artisan('savings:prepare-books')
            ->expectsOutputToContain('no chart of accounts')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_dry_run_on_a_lending_chart_says_what_and_writes_nothing(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'lending_company');
        $before = $this->snapshot();

        $this->artisan('savings:prepare-books', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('3 account(s) added, 4 role(s) mapped')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_run_adds_the_accounts_maps_the_roles_audits_and_a_second_run_changes_nothing(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'lending_company');
        $auditBefore = AuditLog::query()->max('id');

        $this->artisan('savings:prepare-books')->assertSuccessful();

        $this->assertSame([
            'interest_expense_deposits' => '5160',
            'savings_deposits' => '2050',
            'time_deposits' => '2060',
            'withholding_tax_deposits' => '2210',
        ], $this->savingsMapping());

        $expense = AccountingAccount::query()->where('code', '5160')->sole();
        $this->assertSame('Interest Expense on Deposits', $expense->name);
        $this->assertSame('expense', $expense->type);
        $this->assertSame('5000', $expense->parent?->code);
        $this->assertSame('2000', AccountingAccount::query()->where('code', '2050')->sole()->parent?->code);

        $rows = AuditLog::query()->where('id', '>', $auditBefore)->get();
        $this->assertCount(7, $rows);
        $this->assertSame([null], $rows->pluck('user_id')->unique()->values()->all());
        $this->assertSame(
            [SavingsBooks::AUDIT_ACCOUNT_ADDED => 3, SavingsBooks::AUDIT_ROLE_MAPPED => 4],
            $rows->countBy('action')->sortKeys()->all(),
        );
        $this->assertStringStartsWith('System change:', (string) $rows->first()->description);

        $after = $this->snapshot();
        $this->artisan('savings:prepare-books')->expectsOutputToContain('0 account(s) added, 0 role(s) mapped, 4 already mapped')->assertSuccessful();
        $this->assertSame($after, $this->snapshot());
    }

    public function test_an_administrators_mapping_is_kept_and_an_unusable_code_is_skipped(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'lending_company');
        $wht = AccountingAccount::query()->where('code', '2220')->value('id');
        AccountingAccountMapping::query()->create(['role' => 'withholding_tax_deposits', 'accounting_account_id' => $wht]);
        AccountingAccount::query()->create(['code' => '2060', 'name' => 'Accrued Rent', 'type' => 'liability', 'is_active' => true]);

        $result = app(SavingsBooks::class)->prepare(false);

        $this->assertSame('2220', $this->savingsMapping()['withholding_tax_deposits']);
        $this->assertArrayNotHasKey('time_deposits', $this->savingsMapping());
        $this->assertSame([['role' => 'time_deposits', 'reason' => '2060 is already used by Accrued Rent']], $result['skipped']);
    }

    public function test_the_cooperative_template_carries_everything_already(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'cooperative');

        $this->assertSame([
            'interest_expense_deposits' => '5160',
            'savings_deposits' => '2050',
            'time_deposits' => '2060',
            'withholding_tax_deposits' => '2210',
        ], $this->savingsMapping());

        $result = app(SavingsBooks::class)->prepare(false);
        $this->assertSame([], $result['created']);
        $this->assertSame([], $result['mapped']);
    }

    public function test_the_migration_finishes_a_chart_that_has_2050_and_leaves_one_without_it(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'cooperative');
        DB::table('accounting_account_mappings')->whereIn('role', SavingsBooks::ROLES)->delete();
        DB::table('accounting_accounts')->where('code', '5160')->delete();

        (require base_path(self::MIGRATION))->up();

        $this->assertSame([
            'interest_expense_deposits' => '5160',
            'savings_deposits' => '2050',
            'time_deposits' => '2060',
            'withholding_tax_deposits' => '2210',
        ], $this->savingsMapping());
        $this->assertSame(1, AuditLog::query()->where('action', SavingsBooks::AUDIT_ACCOUNT_ADDED)->count());
        $this->assertSame(4, AuditLog::query()->where('action', SavingsBooks::AUDIT_ROLE_MAPPED)->count());

        $after = $this->snapshot();
        (require base_path(self::MIGRATION))->up();
        $this->assertSame($after, $this->snapshot());
    }

    public function test_the_migration_leaves_a_lending_chart_alone(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'lending_company');
        $before = $this->snapshot();

        (require base_path(self::MIGRATION))->up();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->savingsMapping());
    }

    public function test_the_command_and_the_migration_reach_the_same_books(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'cooperative');
        DB::table('accounting_account_mappings')->whereIn('role', SavingsBooks::ROLES)->delete();
        DB::table('accounting_accounts')->where('code', '5160')->delete();

        DB::beginTransaction();
        app(SavingsBooks::class)->prepare(false);
        $byCommand = [$this->savingsMapping(), AccountingAccount::query()->where('code', '5160')->first(['name', 'type', 'cash_flow_category', 'normal_balance'])->toArray()];
        DB::rollBack();

        (require base_path(self::MIGRATION))->up();
        $byMigration = [$this->savingsMapping(), AccountingAccount::query()->where('code', '5160')->first(['name', 'type', 'cash_flow_category', 'normal_balance'])->toArray()];

        $this->assertSame($byCommand, $byMigration);
    }
}
