<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Owner answer (2026-11-11): active staff with no branch and no
 * `branches:view_all` join the main branch. `users:assign-default-branch`
 * (with `--dry-run` to preview) and its migration write one system audit row
 * per user, and a second run changes nothing. With no clear main branch the
 * users are skipped and listed.
 */
class AssignDefaultBranchTest extends TestCase
{
    use SetupLendyPH;

    private const COMMAND = 'users:assign-default-branch';

    private const MIGRATION = 'migrations/2026_11_11_620000_assign_branchless_staff_to_main_branch.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_with_one_active_branch_it_is_the_main_branch(): void
    {
        $this->branch->update(['name' => 'Binangonan', 'code' => 'BIN']);
        Branch::factory()->create(['name' => 'Closed Branch', 'is_active' => false]);
        $user = $this->branchless('loan_officer');

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain("Main branch: Binangonan (BIN, id {$this->branch->id}), the only active branch.")
            ->expectsOutputToContain("{$user->username} (id {$user->id}): joined Binangonan")
            ->expectsOutputToContain('Assigned 1 user(s) to the main branch; 0 skipped.')
            ->assertSuccessful();

        $this->assertSame([$this->branch->id], $this->branchIds($user));
        $this->assertSame($this->branch->id, (int) DB::table('users')->where('id', $user->id)->value('branch_id'));
    }

    public function test_with_several_branches_the_one_marked_main_is_used(): void
    {
        Branch::factory()->create(['name' => 'Taytay', 'code' => 'TAY']);
        $officer = $this->branchless('loan_officer');
        $cashier = $this->branchless('cashier');
        $auditRows = AuditLog::count();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain("Main branch: Main Branch (MAIN, id {$this->branch->id}), the branch marked as the main branch.")
            ->expectsOutputToContain('Assigned 2 user(s) to the main branch; 0 skipped.')
            ->assertSuccessful();

        $this->assertSame([$this->branch->id], $this->branchIds($officer));
        $this->assertSame([$this->branch->id], $this->branchIds($cashier));

        // One row per user, written by the fix as a system change.
        $this->assertSame($auditRows + 2, AuditLog::count());
        $row = AuditLog::query()->where('auditable_id', $officer->id)->latest('id')->firstOrFail();
        $this->assertNull($row->user_id);
        $this->assertNull($row->ip_address);
        $this->assertSame('branches_changed', $row->action);
        $this->assertSame((new User)->getMorphClass(), $row->auditable_type);
        $this->assertSame(['branch_ids' => []], $row->old_values);
        $this->assertSame(['branch_ids' => [$this->branch->id]], $row->new_values);
        $this->assertSame(
            "System correction: Branch assignment for {$officer->username} changed from none to Main Branch (staff with no branch join the main branch).",
            $row->description,
        );
    }

    public function test_a_branch_named_head_office_counts_as_main(): void
    {
        $this->branch->update(['name' => 'Binangonan', 'code' => 'BIN']);
        $head = Branch::factory()->create(['name' => 'Head Office', 'code' => 'HQ1']);
        $user = $this->branchless('cashier');

        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame([$head->id], $this->branchIds($user));
    }

    public function test_with_several_branches_and_no_main_one_the_users_are_skipped_and_listed(): void
    {
        $this->branch->update(['name' => 'Binangonan', 'code' => 'BIN']);
        Branch::factory()->create(['name' => 'Taytay', 'code' => 'TAY']);
        $user = $this->branchless('loan_officer');
        $before = $this->snapshot();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('No main branch: 2 active branches and none is marked as the main branch.')
            ->expectsOutputToContain("{$user->username} (id {$user->id}) skipped: no main branch")
            ->expectsOutputToContain('Assigned 0 user(s) to the main branch; 1 skipped.')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_two_branches_marked_main_are_unclear_and_skipped(): void
    {
        Branch::factory()->create(['name' => 'Head Office', 'code' => 'HO']);
        $user = $this->branchless('loan_officer');
        $before = $this->snapshot();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('No main branch: 2 active branches are marked as the main branch.')
            ->expectsOutputToContain("{$user->username} (id {$user->id}) skipped: no main branch")
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_view_all_holders_admins_and_others_are_left_alone(): void
    {
        $manager = $this->branchless('manager');
        $admin = $this->branchless('admin');
        $superAdmin = $this->branchless('super_admin');
        $direct = $this->branchless('loan_officer');
        $direct->givePermissionTo(Permission::findByName('branches:view_all', 'web'));
        $deactivated = $this->branchless('loan_officer', ['status' => 'inactive']);
        $legacyOnly = $this->branchless('cashier');
        DB::table('users')->where('id', $legacyOnly->id)->update(['branch_id' => $this->branch->id]);
        $assigned = User::factory()->create(['branch_id' => $this->branch->id]);
        $assigned->assignRole('cashier');
        $staff = $this->branchless('collector');

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('Assigned 1 user(s) to the main branch; 0 skipped.')
            ->assertSuccessful();

        $this->assertSame([$this->branch->id], $this->branchIds($staff));
        foreach ([$manager, $admin, $superAdmin, $direct, $deactivated, $legacyOnly] as $user) {
            $this->assertSame([], $this->branchIds($user), $user->username);
        }
        $this->assertNull(DB::table('users')->where('id', $manager->id)->value('branch_id'));
        $this->assertSame([$this->branch->id], $this->branchIds($assigned));
    }

    public function test_a_view_all_role_that_is_deactivated_grants_nothing_so_the_user_joins(): void
    {
        $user = $this->branchless('manager');
        DB::table('roles')->where('name', 'manager')->update(['is_active' => false]);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('Assigned 1 user(s) to the main branch; 0 skipped.')
            ->assertSuccessful();

        $this->assertSame([$this->branch->id], $this->branchIds($user));
    }

    public function test_a_dry_run_lists_what_would_change_and_writes_nothing(): void
    {
        $user = $this->branchless('loan_officer');
        $before = $this->snapshot();

        $this->artisan(self::COMMAND, ['--dry-run' => true])
            ->expectsOutputToContain('Dry run. Nothing is written.')
            ->expectsOutputToContain("{$user->username} (id {$user->id}): would join Main Branch")
            ->expectsOutputToContain('Would assign 1 user(s) to the main branch; 0 skipped.')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->branchless('loan_officer');
        $this->artisan(self::COMMAND)->assertSuccessful();
        $after = $this->snapshot();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('Assigned 0 user(s) to the main branch; 0 skipped.')
            ->assertSuccessful();

        $this->assertSame($after, $this->snapshot());
    }

    public function test_a_user_given_a_branch_while_the_fix_ran_is_left_as_it_is(): void
    {
        $other = Branch::factory()->create(['name' => 'Taytay', 'code' => 'TAY']);
        $user = $this->branchless('loan_officer');
        $raced = false;
        DB::listen(function ($query) use (&$raced, $user, $other): void {
            if (! $raced && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from `users`') && str_contains($query->sql, 'model_has_roles')) {
                $raced = true;
                DB::table('users')->where('id', $user->id)->update(['branch_id' => $other->id]);
                DB::table('branch_user')->insert(['user_id' => $user->id, 'branch_id' => $other->id]);
            }
        });
        $auditRows = AuditLog::count();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain("{$user->username} (id {$user->id}) skipped: given a branch while the fix ran")
            ->expectsOutputToContain('Assigned 0 user(s) to the main branch; 1 skipped.')
            ->assertSuccessful();

        $this->assertTrue($raced);
        $this->assertSame([$other->id], $this->branchIds($user));
        $this->assertSame($auditRows, AuditLog::count());
    }

    public function test_the_migration_applies_the_same_fix_and_runs_safely_twice(): void
    {
        $user = $this->branchless('loan_officer');
        $auditRows = AuditLog::count();
        Log::spy();

        $this->migration()->up();

        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message): bool => $message === 'branchless staff: 1 assigned to the main branch, 0 skipped',
        );
        $this->assertSame([$this->branch->id], $this->branchIds($user));
        $this->assertSame($auditRows + 1, AuditLog::count());

        $after = $this->snapshot();
        $this->migration()->up();
        $this->assertSame($after, $this->snapshot());
    }

    public function test_the_migration_and_the_command_reach_the_same_outcome(): void
    {
        $cases = [
            'main branch' => fn () => null,
            'no main branch' => fn () => Branch::factory()->create(['name' => 'Taytay', 'code' => 'TAY'])
                && $this->branch->update(['name' => 'Binangonan', 'code' => 'BIN']),
        ];

        foreach ($cases as $case => $arrange) {
            DB::beginTransaction();
            $arrange();
            $this->branchless('loan_officer');
            $this->branchless('cashier');
            $this->branchless('manager');
            $fromId = (int) AuditLog::max('id');

            DB::beginTransaction();
            $this->artisan(self::COMMAND)->assertSuccessful();
            $byCommand = $this->outcome($fromId);
            DB::rollBack();

            $this->migration()->up();
            $byMigration = $this->outcome($fromId);
            DB::rollBack();

            $this->assertSame($byCommand, $byMigration, $case);
            $this->assertCount($case === 'main branch' ? 2 : 0, $byMigration['audit_rows'], $case);
        }
    }

    public function test_the_migration_depends_on_no_application_class(): void
    {
        $this->assertDoesNotMatchRegularExpression('/^use App\\\\/m', file_get_contents(database_path(self::MIGRATION)));
    }

    /**
     * An active user with the role and no branch at all: no column, no pivot.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function branchless(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['branch_id' => null, ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return list<int>
     */
    private function branchIds(User $user): array
    {
        return DB::table('branch_user')->where('user_id', $user->id)->orderBy('branch_id')->pluck('branch_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return array{users: array<int, mixed>, pivot: list<string>, audit_rows: int}
     */
    private function snapshot(): array
    {
        return [
            'users' => DB::table('users')->orderBy('id')->get(['id', 'branch_id', 'updated_at'])->map(fn ($row) => (array) $row)->all(),
            'pivot' => DB::table('branch_user')->orderBy('id')->get()->map(fn ($row) => "{$row->user_id}:{$row->branch_id}")->all(),
            'audit_rows' => AuditLog::count(),
        ];
    }

    /**
     * The users' branches and the audit rows written after `$fromId`, without
     * ids and times.
     *
     * @return array{users: array<int, mixed>, pivot: list<string>, audit_rows: list<array<string, mixed>>}
     */
    private function outcome(int $fromId): array
    {
        return [
            'users' => DB::table('users')->orderBy('id')->pluck('branch_id', 'id')->all(),
            'pivot' => DB::table('branch_user')->orderBy('user_id')->get()->map(fn ($row) => "{$row->user_id}:{$row->branch_id}")->all(),
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
