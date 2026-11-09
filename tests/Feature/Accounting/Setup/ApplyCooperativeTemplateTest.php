<?php

namespace Tests\Feature\Accounting\Setup;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Models\AuditLog;
use App\Services\Accounting\CashFlowCategories;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\CooperativeTemplateBackfill;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `accounting:apply-cooperative-template`: the cooperative template's rows on
 * a deployment that already keeps the lending chart (binhs-coop staging), and
 * `net_surplus` re-pointed from 3030 to 3080 Undivided Net Surplus.
 *
 * Add only. A row whose code is taken by another account is skipped and
 * logged, never renamed. The mapping moves only while it still posts to 3030
 * and no user ever changed it. One system audit row per account added and for
 * the re-point. A second run writes nothing.
 */
class ApplyCooperativeTemplateTest extends TestCase
{
    use SetupLendyPH;

    private const COMMAND = 'accounting:apply-cooperative-template';

    private const CODES = ['2050', '2060', '2070', '2080', '3070', '3071', '3072', '3073', '3074', '3080'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_it_adds_the_rows_to_a_lending_chart_and_re_points_net_surplus(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id);
        Auth::logout();
        $before = AuditLog::query()->max('id') ?? 0;

        $output = $this->runCommand(['--force' => true]);

        $this->assertSame(0, $this->lastExit);
        $this->assertStringContainsString('Added 2050 Savings Deposits', $output);
        $this->assertStringContainsString('Re-pointed net_surplus from 3030 Retained Earnings to 3080 Undivided Net Surplus', $output);

        $chart = $this->chartFromTheTemplate();
        foreach (ChartOfAccountsSeeder::COOPERATIVE_ADDITIONS as $row) {
            $account = AccountingAccount::query()->where('code', $row['code'])->sole();
            $this->assertSame($row['name'], $account->name);
            $this->assertSame($row['type'], $account->type);
            $this->assertSame($row['parent'], $this->codeOf($account->parent_id));
            $this->assertSame($row['is_group'] ?? false, $account->is_group);
            $this->assertSame($chart[$row['code']]['cash_flow_category'], $account->cash_flow_category);
            $this->assertSame($chart[$row['code']]['normal_balance'], $account->normal_balance);
            $this->assertTrue($account->is_active);
        }

        $this->assertSame('3080', $this->netSurplusCode());

        $rows = AuditLog::query()->where('id', '>', $before)->orderBy('id')->get();
        $this->assertCount(11, $rows);
        $this->assertSame([null], $rows->pluck('user_id')->unique()->values()->all());
        $this->assertSame(10, $rows->where('action', CooperativeTemplateBackfill::AUDIT_ACCOUNT_ADDED)->count());
        $this->assertSame(
            self::CODES,
            $rows->where('action', CooperativeTemplateBackfill::AUDIT_ACCOUNT_ADDED)->map(fn (AuditLog $row) => $row->new_values['code'])->values()->all(),
        );

        $repoint = $rows->where('action', CooperativeTemplateBackfill::AUDIT_ROLE_REPOINTED)->sole();
        $this->assertSame(AccountingAccountMapping::class, $repoint->auditable_type);
        $this->assertSame('3030', $repoint->old_values['account_code']);
        $this->assertSame('3080', $repoint->new_values['account_code']);
        $this->assertStringStartsWith('System change:', (string) $repoint->description);
    }

    public function test_the_result_matches_a_freshly_seeded_cooperative_chart(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id);
        $this->runCommand(['--force' => true]);
        $applied = $this->snapshot();

        DB::table('accounting_account_mappings')->delete();
        DB::table('accounting_accounts')->update(['parent_id' => null]);
        DB::table('accounting_accounts')->delete();
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'cooperative');

        $this->assertSame($this->snapshot(), $applied);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id);
        $accounts = AccountingAccount::query()->count();
        $audits = AuditLog::query()->count();

        $output = $this->runCommand(['--dry-run' => true]);

        $this->assertSame(0, $this->lastExit);
        $this->assertStringContainsString('Would add 3070 Statutory Funds', $output);
        $this->assertStringContainsString('Would re-point net_surplus from 3030 Retained Earnings to 3080 Undivided Net Surplus', $output);
        $this->assertSame($accounts, AccountingAccount::query()->count());
        $this->assertSame($audits, AuditLog::query()->count());
        $this->assertSame('3030', $this->netSurplusCode());
    }

    public function test_without_force_it_asks_and_a_no_writes_nothing(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id);
        $accounts = AccountingAccount::query()->count();

        $this->artisan(self::COMMAND)
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->assertExitCode(1);

        $this->assertSame($accounts, AccountingAccount::query()->count());
        $this->assertSame('3030', $this->netSurplusCode());
    }

    public function test_a_second_run_writes_nothing(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id);
        $this->runCommand(['--force' => true]);
        $accounts = AccountingAccount::query()->count();
        $audits = AuditLog::query()->count();
        $updated = AccountingAccountMapping::query()->max('updated_at');

        $output = $this->runCommand(['--force' => true]);

        $this->assertSame(0, $this->lastExit);
        $this->assertStringContainsString('3080 Undivided Net Surplus is already in the chart', $output);
        $this->assertStringContainsString('net_surplus already posts to 3080', $output);
        $this->assertSame($accounts, AccountingAccount::query()->count());
        $this->assertSame($audits, AuditLog::query()->count());
        $this->assertEquals($updated, AccountingAccountMapping::query()->max('updated_at'));
    }

    public function test_a_mapping_a_user_changed_is_kept(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id);
        $this->putJson('/api/accounting/settings/account-mapping', ['net_surplus' => $this->id('3020')])->assertOk();

        $output = $this->runCommand(['--force' => true]);

        $this->assertStringContainsString('net_surplus skipped', $output);
        $this->assertSame('3020', $this->netSurplusCode());
        $this->assertSame(10, AccountingAccount::query()->whereIn('code', self::CODES)->count());
    }

    public function test_a_mapping_a_user_changed_and_changed_back_is_kept(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id);
        $this->putJson('/api/accounting/settings/account-mapping', ['net_surplus' => $this->id('3020')])->assertOk();
        $this->putJson('/api/accounting/settings/account-mapping', ['net_surplus' => $this->id('3030')])->assertOk();

        $output = $this->runCommand(['--force' => true]);

        $this->assertStringContainsString('net_surplus skipped', $output);
        $this->assertSame('3030', $this->netSurplusCode());
    }

    public function test_an_existing_3080_with_another_name_is_skipped_and_never_renamed(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id);
        $taken = AccountingAccount::query()->create([
            'code' => '3080', 'name' => 'Members Fund', 'type' => 'equity',
            'parent_id' => $this->id('3000'), 'is_group' => false, 'is_active' => true,
        ]);

        $output = $this->runCommand(['--force' => true]);

        $this->assertStringContainsString('3080 skipped', $output);
        $this->assertStringContainsString('Members Fund', $output);
        $this->assertSame('Members Fund', $taken->fresh()->name);
        $this->assertSame('3030', $this->netSurplusCode());
        $this->assertSame(9, AccountingAccount::query()->whereIn('code', array_diff(self::CODES, ['3080']))->count());
    }

    public function test_a_taken_heading_skips_its_children(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id);
        AccountingAccount::query()->create([
            'code' => '3070', 'name' => 'Donated Capital', 'type' => 'equity',
            'parent_id' => $this->id('3000'), 'is_group' => false, 'is_active' => true,
        ]);

        $output = $this->runCommand(['--force' => true]);

        $this->assertStringContainsString('3070 skipped', $output);
        $this->assertStringContainsString('3071 skipped', $output);
        $this->assertSame(0, AccountingAccount::query()->whereIn('code', ['3071', '3072', '3073', '3074'])->count());
        $this->assertSame('Donated Capital', AccountingAccount::query()->where('code', '3070')->value('name'));
        $this->assertSame('3080', $this->netSurplusCode());
    }

    public function test_a_missing_parent_skips_the_row(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id);
        $liabilities = $this->id('2000');
        DB::table('accounting_accounts')->where('parent_id', $liabilities)->update(['parent_id' => null]);
        DB::table('accounting_accounts')->where('id', $liabilities)->delete();

        $output = $this->runCommand(['--force' => true]);

        $this->assertStringContainsString('2050 skipped', $output);
        $this->assertSame(0, AccountingAccount::query()->whereIn('code', ['2050', '2060', '2070', '2080'])->count());
        $this->assertSame(6, AccountingAccount::query()->whereIn('code', ['3070', '3071', '3072', '3073', '3074', '3080'])->count());
    }

    public function test_with_no_chart_it_does_nothing(): void
    {
        $audits = AuditLog::query()->count();

        $output = $this->runCommand(['--force' => true]);

        $this->assertSame(0, $this->lastExit);
        $this->assertStringContainsString('No chart of accounts; nothing to do', $output);
        $this->assertSame(0, AccountingAccount::query()->count());
        $this->assertSame($audits, AuditLog::query()->count());
    }

    // ── Helpers ──

    private int $lastExit = -1;

    /** @param  array<string, mixed>  $options */
    private function runCommand(array $options): string
    {
        $this->lastExit = Artisan::call(self::COMMAND, $options);

        return Artisan::output();
    }

    private function id(string $code): int
    {
        return (int) AccountingAccount::query()->where('code', $code)->value('id');
    }

    private function codeOf(?int $id): ?string
    {
        return $id === null ? null : AccountingAccount::query()->whereKey($id)->value('code');
    }

    private function netSurplusCode(): ?string
    {
        return $this->codeOf(AccountingAccountMapping::query()->where('role', 'net_surplus')->value('accounting_account_id'));
    }

    /**
     * What every other row of the chart gets: the default cash flow category
     * and the normal balance of its type.
     *
     * @return array<string, array{cash_flow_category: string, normal_balance: string}>
     */
    private function chartFromTheTemplate(): array
    {
        $rows = [];

        foreach (ChartOfAccountsSeeder::COOPERATIVE_ADDITIONS as $row) {
            $rows[$row['code']] = [
                'cash_flow_category' => CashFlowCategories::defaultFor($row['type'], null),
                'normal_balance' => in_array($row['type'], ['asset', 'expense'], true) ? 'debit' : 'credit',
            ];
        }

        return $rows;
    }

    /** @return array<string, array<string, mixed>> */
    private function snapshot(): array
    {
        $codes = AccountingAccount::query()->pluck('code', 'id');
        $accounts = [];

        foreach (AccountingAccount::query()->inCodeOrder()->get() as $account) {
            $accounts[$account->code] = [
                $account->name, $account->type, $account->normal_balance, $account->is_contra, $account->is_group,
                $account->is_active, $account->cash_kind, $account->cash_flow_category,
                $account->parent_id === null ? null : $codes[$account->parent_id],
            ];
        }

        $mapping = AccountingAccountMapping::query()->orderBy('role')->get()
            ->mapWithKeys(fn (AccountingAccountMapping $row) => [$row->role => $codes[$row->accounting_account_id]])
            ->all();

        return ['accounts' => $accounts, 'mapping' => $mapping];
    }
}
