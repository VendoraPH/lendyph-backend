<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PortalUser;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The promotion upgrades production from main in one `migrate` run, so a
 * migration's audit row can be written before `audit_logs` has the columns
 * added later (actor_type and portal_user_id on 2026-11-11, branch_id on
 * 2026-11-23). During a migrate run AuditLogService writes only the ones that
 * exist, and reads the table again after each migration. Outside a run it
 * writes all three, so a missing column still fails.
 */
class AuditLogUpgradeFromMainTest extends TestCase
{
    /**
     * Drops and adds audit_logs columns. MySQL implicitly commits the open
     * transaction on any DDL, so a rollback wouldn't undo it; this needs the
     * real `migrate:fresh` before each test that Tests\TestCase gives when this
     * is false.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const LATER_COLUMNS = ['actor_type', 'portal_user_id', 'branch_id'];

    protected function tearDown(): void
    {
        AuditLogService::migrationsEnded();

        parent::tearDown();
    }

    public function test_outside_a_migrate_run_a_missing_column_still_fails(): void
    {
        $this->dropLaterColumns();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Unknown column 'actor_type'");

        AuditLogService::log(action: 'updated', auditable: Branch::query()->firstOrFail(), asSystem: true);
    }

    public function test_during_a_migrate_run_it_writes_the_row_without_the_columns_main_lacks(): void
    {
        $this->startMigrateRun();
        $this->dropLaterColumns();
        $admin = User::query()->where('username', 'super_admin')->firstOrFail();

        $log = AuditLogService::log(
            action: 'role_permissions_changed',
            auditable: Branch::query()->firstOrFail(),
            description: 'Written by a migration before the later columns exist',
            userId: $admin->id,
        );

        $row = (array) DB::table('audit_logs')->where('id', $log->id)->first();
        $this->assertSame($admin->id, $row['user_id']);
        $this->assertSame('role_permissions_changed', $row['action']);
        $this->assertSame('Written by a migration before the later columns exist', $row['description']);
        foreach (self::LATER_COLUMNS as $column) {
            $this->assertArrayNotHasKey($column, $row);
        }
    }

    public function test_it_records_the_branch_once_a_migration_adds_branch_id(): void
    {
        $this->startMigrateRun();
        $this->dropLaterColumns();
        $branch = Branch::query()->firstOrFail();

        $before = AuditLogService::log(action: 'updated', auditable: $branch, asSystem: true);
        $this->runMigration('2026_11_23_100000_add_branch_id_to_audit_logs_table.php');
        $after = AuditLogService::log(action: 'updated', auditable: $branch, asSystem: true);

        $this->assertNull(DB::table('audit_logs')->where('id', $before->id)->value('branch_id'));
        $this->assertSame($branch->id, DB::table('audit_logs')->where('id', $after->id)->value('branch_id'));
    }

    public function test_it_records_the_member_actor_once_a_migration_adds_the_portal_columns(): void
    {
        $this->startMigrateRun();
        $this->dropLaterColumns();
        $member = PortalUser::factory()->create();
        $branch = Branch::query()->firstOrFail();

        $before = AuditLogService::log(action: 'updated', auditable: $branch, member: $member);
        $this->runMigration('2026_11_11_700100_add_portal_columns.php');
        $after = AuditLogService::log(action: 'updated', auditable: $branch, member: $member);

        $earlier = DB::table('audit_logs')->where('id', $before->id)->first(['actor_type', 'portal_user_id']);
        $this->assertNull($earlier->actor_type);
        $this->assertNull($earlier->portal_user_id);
        $row = DB::table('audit_logs')->where('id', $after->id)->first(['user_id', 'actor_type', 'portal_user_id']);
        $this->assertNull($row->user_id);
        $this->assertSame(AuditLogService::ACTOR_MEMBER, $row->actor_type);
        $this->assertSame($member->id, $row->portal_user_id);
    }

    /** As `php artisan migrate` does before its first pending migration. */
    private function startMigrateRun(): void
    {
        event(new MigrationsStarted('up'));
    }

    /** One migration's up(), followed by the event the migrator fires after it. */
    private function runMigration(string $file): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/'.$file);
        $migration->up();

        event(new MigrationEnded($migration, 'up'));
    }

    /**
     * Put audit_logs back to the columns main's schema has, as the two later
     * migrations' down() would.
     */
    private function dropLaterColumns(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'created_at']);
            $table->dropColumn('branch_id');
            $table->dropConstrainedForeignId('portal_user_id');
            $table->dropColumn('actor_type');
        });

        // A migration's down() is followed by MigrationEnded, which clears the list.
        AuditLogService::forgetColumns();
    }
}
