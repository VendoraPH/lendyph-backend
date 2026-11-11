<?php

namespace Tests\Feature\Accounting\Reconciliation;

use App\Models\AccountingAccount;
use App\Models\AuditLog;
use App\Models\Branch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The data fix that gives every existing money account the main branch, so
 * each branch reconciles its own accounts (owner answer 9, 2026-11-11):
 * `accounting:assign-cash-account-branches [--dry-run]`, and the migration
 * that runs the same fix on deploy.
 *
 * The main branch is the only active branch, else the one active branch the
 * table marks as the main branch or head office (code MAIN, HO or HQ, or the
 * name Main, Main Branch, Main Office, Head Office or Headquarters). When that is unclear nothing
 * is written and the accounts are listed.
 */
class AssignCashAccountBranchesTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const MIGRATION = 'migrations/2026_11_11_400100_assign_cash_accounts_to_main_branch.php';

    private const MONEY_CODES = ['1010', '1020', '1030', '1040'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    /** @return array<string, int|null> account code => branch id, for the money accounts */
    private function branches(): array
    {
        return AccountingAccount::query()
            ->whereNotNull('cash_kind')
            ->orderBy('code')
            ->pluck('branch_id', 'code')
            ->map(static fn ($id): ?int => $id === null ? null : (int) $id)
            ->all();
    }

    public function test_the_dry_run_lists_what_it_would_assign_and_writes_nothing(): void
    {
        $audits = AuditLog::query()->count();

        $this->artisan('accounting:assign-cash-account-branches', ['--dry-run' => true])
            ->expectsOutputToContain('1040 Bank Accounts: would belong to Main Branch')
            ->expectsOutputToContain('Would assign 4 money account(s) to Main Branch; 0 left as they are.')
            ->assertSuccessful();

        $this->assertSame(array_fill_keys(self::MONEY_CODES, null), $this->branches());
        $this->assertSame($audits, AuditLog::query()->count());
    }

    public function test_with_one_active_branch_every_money_account_joins_it_with_one_audit_row_each(): void
    {
        Branch::factory()->create(['name' => 'Closed Branch', 'code' => 'OLD', 'is_active' => false]);
        $fromId = (int) AuditLog::query()->max('id');

        $this->artisan('accounting:assign-cash-account-branches')
            ->expectsOutputToContain('Assigned 4 money account(s) to Main Branch; 0 left as they are.')
            ->assertSuccessful();

        $this->assertSame(array_fill_keys(self::MONEY_CODES, $this->branch->id), $this->branches());
        // Accounts that hold no money stay with no branch.
        $this->assertSame(0, AccountingAccount::query()->whereNull('cash_kind')->whereNotNull('branch_id')->count());

        $rows = AuditLog::query()->where('id', '>', $fromId)->orderBy('id')->get();
        $this->assertCount(4, $rows);

        foreach ($rows as $row) {
            $this->assertNull($row->user_id);
            $this->assertSame('updated', $row->action);
            $this->assertSame(AccountingAccount::class, $row->auditable_type);
            $this->assertSame(['branch_id' => null], $row->old_values);
            $this->assertSame(['branch_id' => $this->branch->id], $row->new_values);
            $this->assertStringStartsWith('System correction: ', $row->description);
        }

        $this->assertSame(
            'System correction: Account 1010 Cash on Hand now belongs to Main Branch, so that branch reconciles it.',
            $rows->first()->description,
        );
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->artisan('accounting:assign-cash-account-branches')->assertSuccessful();
        $audits = AuditLog::query()->count();

        $this->artisan('accounting:assign-cash-account-branches')
            ->expectsOutputToContain('Assigned 0 money account(s)')
            ->assertSuccessful();

        $this->assertSame($audits, AuditLog::query()->count());
    }

    public function test_an_account_that_already_has_a_branch_is_left_alone(): void
    {
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        AccountingAccount::query()->whereKey($this->account('1020'))->update(['branch_id' => $far->id]);

        $this->artisan('accounting:assign-cash-account-branches')
            ->expectsOutputToContain('Assigned 3 money account(s) to Main Branch')
            ->assertSuccessful();

        $this->assertSame($far->id, $this->branches()['1020']);
        $this->assertSame($this->branch->id, $this->branches()['1010']);
    }

    public function test_with_several_branches_the_one_marked_main_is_used(): void
    {
        Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $this->branch->update(['name' => 'Head Office', 'code' => 'HQ']);

        $this->artisan('accounting:assign-cash-account-branches')
            ->expectsOutputToContain('Assigned 4 money account(s) to Head Office')
            ->assertSuccessful();

        $this->assertSame(array_fill_keys(self::MONEY_CODES, $this->branch->id), $this->branches());
    }

    public function test_a_branch_named_headquarters_or_main_office_is_the_main_one(): void
    {
        Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);

        foreach (['Headquarters', '  main   OFFICE '] as $name) {
            DB::beginTransaction();
            $this->branch->update(['name' => $name, 'code' => 'POB']);

            $this->artisan('accounting:assign-cash-account-branches', ['--dry-run' => true])
                ->expectsOutputToContain('Would assign 4 money account(s)')
                ->assertSuccessful();
            DB::rollBack();
        }
    }

    public function test_with_several_branches_and_no_main_one_it_skips_and_lists_every_account(): void
    {
        Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $this->branch->update(['name' => 'Poblacion', 'code' => 'POB']);
        $audits = AuditLog::query()->count();

        $this->artisan('accounting:assign-cash-account-branches')
            ->expectsOutputToContain('1010 Cash on Hand left with no branch: no main branch could be decided')
            ->expectsOutputToContain('Assigned 0 money account(s); 4 left as they are.')
            ->assertSuccessful();

        $this->assertSame(array_fill_keys(self::MONEY_CODES, null), $this->branches());
        $this->assertSame($audits, AuditLog::query()->count());
    }

    public function test_two_branches_marked_main_is_unclear_too(): void
    {
        Branch::factory()->create(['name' => 'Head Office', 'code' => 'HO']);

        $this->artisan('accounting:assign-cash-account-branches')
            ->expectsOutputToContain('Assigned 0 money account(s); 4 left as they are.')
            ->assertSuccessful();

        $this->assertSame(array_fill_keys(self::MONEY_CODES, null), $this->branches());
    }

    public function test_the_migration_depends_on_no_application_class(): void
    {
        $this->assertDoesNotMatchRegularExpression('/^use App\\\\/m', file_get_contents(database_path(self::MIGRATION)));
    }

    public function test_the_migration_and_the_command_reach_the_same_outcome(): void
    {
        $cases = [
            'one branch' => fn () => null,
            'a main one among several' => fn () => Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']),
            'no main one' => function (): void {
                Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
                $this->branch->update(['name' => 'Poblacion', 'code' => 'POB']);
            },
        ];

        foreach ($cases as $case => $arrange) {
            DB::beginTransaction();
            $arrange();
            $fromId = (int) AuditLog::query()->max('id');

            DB::beginTransaction();
            $this->artisan('accounting:assign-cash-account-branches')->assertSuccessful();
            $byCommand = $this->outcome($fromId);
            DB::rollBack();

            $this->migration()->up();
            $byMigration = $this->outcome($fromId);

            $this->migration()->up();
            $this->assertSame($byMigration, $this->outcome($fromId), "{$case}: a second run changed something");
            DB::rollBack();

            $this->assertSame($byCommand, $byMigration, $case);
        }
    }

    /**
     * @return array{accounts: array<string, int|null>, audit_rows: list<array<string, mixed>>}
     */
    private function outcome(int $fromId): array
    {
        return [
            'accounts' => $this->branches(),
            'audit_rows' => AuditLog::query()->where('id', '>', $fromId)->orderBy('auditable_id')->get()
                ->map(fn (AuditLog $row): array => $row->only(['user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'ip_address', 'user_agent', 'description']))
                ->all(),
        ];
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }
}
