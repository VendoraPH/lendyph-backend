<?php

namespace Tests\Feature\Phase6\Branches;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\DefaultBranchAssignment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE1, data fix 1 (multi-branch decision 4): the main-office flag
 * is set on the one branch today's main-branch rule finds (the only active
 * branch, else the one active branch named as the main branch). With none or
 * several, nothing is written and the branches are listed. Each change is one
 * system audit row; a second run changes nothing. The migration runs the same
 * fix on deploy, and `branches:mark-main-office --dry-run` previews it.
 * DefaultBranchAssignment then reads the flag.
 */
class MarkMainOfficeTest extends TestCase
{
    use SetupLendyPH;

    private const COMMAND = 'branches:mark-main-office';

    private const MIGRATION = 'migrations/2026_11_19_310200_mark_the_main_office.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_only_active_branch_is_marked_with_one_system_audit_row(): void
    {
        Branch::factory()->create(['name' => 'Old Branch', 'status' => 'suspended']);
        $auditRows = AuditLog::count();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain("Main office: Main Branch (MAIN, id {$this->branch->id}), the only active branch.")
            ->expectsOutputToContain('Marked Main Branch as the main office.')
            ->assertSuccessful();

        $this->assertTrue($this->branch->fresh()->is_main_office);
        $this->assertSame($auditRows + 1, AuditLog::count());

        $row = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertNull($row->user_id);
        $this->assertSame('updated', $row->action);
        $this->assertSame($this->branch->id, $row->auditable_id);
        $this->assertSame(['is_main_office' => false], $row->old_values);
        $this->assertSame(['is_main_office' => true], $row->new_values);
        $this->assertSame('System correction: Branch "Main Branch" (MAIN) marked as the main office (the only active branch).', $row->description);
    }

    public function test_with_several_branches_the_one_named_main_is_marked(): void
    {
        Branch::factory()->create(['name' => 'Tawi-tawi', 'code' => 'TAW']);

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('the branch named as the main branch')
            ->assertSuccessful();

        $this->assertSame([$this->branch->id], Branch::query()->where('is_main_office', true)->pluck('id')->all());
    }

    public function test_with_no_clear_main_branch_nothing_is_written_and_the_branches_are_listed(): void
    {
        $this->branch->update(['name' => 'Binangonan', 'code' => 'BIN']);
        $other = Branch::factory()->create(['name' => 'Taytay', 'code' => 'TAY']);
        $auditRows = AuditLog::count();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('No main office: 2 active branches and none is named as the main branch.')
            ->expectsOutputToContain("Binangonan (BIN, id {$this->branch->id})")
            ->expectsOutputToContain("Taytay (TAY, id {$other->id})")
            ->assertFailed();

        $this->assertSame(0, Branch::query()->where('is_main_office', true)->count());
        $this->assertSame($auditRows, AuditLog::count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $auditRows = AuditLog::count();

        $this->artisan(self::COMMAND, ['--dry-run' => true])
            ->expectsOutputToContain('Dry run. Nothing is written.')
            ->expectsOutputToContain('Would mark Main Branch as the main office.')
            ->assertSuccessful();

        $this->assertFalse($this->branch->fresh()->is_main_office);
        $this->assertSame($auditRows, AuditLog::count());
    }

    public function test_an_existing_main_office_is_left_alone_and_a_second_run_changes_nothing(): void
    {
        $this->artisan(self::COMMAND)->assertSuccessful();
        $auditRows = AuditLog::count();

        $this->artisan(self::COMMAND)
            ->expectsOutputToContain('Main Branch is already the main office. Nothing to do.')
            ->assertSuccessful();

        $this->assertSame($auditRows, AuditLog::count());
    }

    public function test_the_migration_and_the_command_reach_the_same_outcome(): void
    {
        $cases = [
            'one active branch' => fn () => null,
            'no clear main branch' => fn () => Branch::factory()->create(['name' => 'Taytay', 'code' => 'TAY'])
                && $this->branch->update(['name' => 'Binangonan', 'code' => 'BIN']),
        ];

        foreach ($cases as $case => $arrange) {
            DB::beginTransaction();
            $arrange();
            $fromId = (int) AuditLog::max('id');

            DB::beginTransaction();
            $this->artisan(self::COMMAND)->assertExitCode($case === 'one active branch' ? 0 : 1);
            $byCommand = $this->outcome($fromId);
            DB::rollBack();

            Log::spy();
            $this->migration()->up();
            $byMigration = $this->outcome($fromId);
            DB::rollBack();

            $this->assertSame($byCommand, $byMigration, $case);
            $this->assertCount($case === 'one active branch' ? 1 : 0, $byMigration['audit_rows'], $case);
        }
    }

    public function test_the_migration_runs_safely_twice_and_depends_on_no_application_class(): void
    {
        Log::spy();
        $this->migration()->up();
        $after = AuditLog::count();
        $this->migration()->up();

        $this->assertSame($after, AuditLog::count());
        $this->assertTrue($this->branch->fresh()->is_main_office);
        $this->assertDoesNotMatchRegularExpression('/^use App\\\\/m', file_get_contents(database_path(self::MIGRATION)));
    }

    public function test_default_branch_assignment_reads_the_main_office_flag(): void
    {
        // Named as the main branch, but another branch carries the flag.
        $flagged = Branch::factory()->create(['name' => 'Tawi-tawi', 'code' => 'TAW']);
        DB::table('branches')->where('id', $flagged->id)->update(['is_main_office' => true]);
        $user = User::factory()->create(['branch_id' => null]);
        $user->assignRole('loan_officer');

        $this->assertSame(
            ['id' => $flagged->id, 'name' => 'Tawi-tawi', 'code' => 'TAW', 'basis' => 'the main office'],
            DefaultBranchAssignment::mainBranch()['branch'],
        );

        app(DefaultBranchAssignment::class)->run(false);

        $this->assertSame([$flagged->id], DB::table('branch_user')->where('user_id', $user->id)->pluck('branch_id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * @return array{flags: array<int, int>, audit_rows: list<array<string, mixed>>}
     */
    private function outcome(int $fromId): array
    {
        return [
            'flags' => DB::table('branches')->orderBy('id')->pluck('is_main_office', 'id')->map(fn ($flag) => (int) $flag)->all(),
            'audit_rows' => AuditLog::query()->where('id', '>', $fromId)->orderBy('id')->get()
                ->map(fn (AuditLog $row): array => $row->only(['user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'ip_address', 'user_agent', 'description']))
                ->all(),
        ];
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }
}
