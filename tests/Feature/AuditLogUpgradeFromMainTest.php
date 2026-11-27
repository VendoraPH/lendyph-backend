<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The promotion upgrades production from main in one `migrate` run, so a
 * migration's audit row can be written before `audit_logs` has the columns
 * added later (actor_type and portal_user_id on 2026-11-11, branch_id on
 * 2026-11-23). AuditLogService writes only the ones that exist, and reads the
 * table again after each migration.
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

    public function test_it_writes_the_row_without_the_columns_main_lacks(): void
    {
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

    public function test_it_reads_the_columns_again_after_a_migration_adds_one(): void
    {
        $this->dropLaterColumns();
        $branch = Branch::query()->firstOrFail();

        $before = AuditLogService::log(action: 'updated', auditable: $branch, asSystem: true);

        $migration = $this->migration('2026_11_23_100000_add_branch_id_to_audit_logs_table.php');
        $migration->up();
        event(new MigrationEnded($migration, 'up'));

        $after = AuditLogService::log(action: 'updated', auditable: $branch, asSystem: true);

        $this->assertNull(DB::table('audit_logs')->where('id', $before->id)->value('branch_id'));
        $this->assertSame($branch->id, DB::table('audit_logs')->where('id', $after->id)->value('branch_id'));
    }

    public function test_it_reads_the_table_once_per_process_and_writes_every_column(): void
    {
        $branch = Branch::query()->firstOrFail();
        AuditLogService::forgetColumns();

        $lookups = 0;
        DB::listen(function ($query) use (&$lookups): void {
            if (str_contains(strtolower($query->sql), 'information_schema.columns')) {
                $lookups++;
            }
        });

        $first = AuditLogService::log(action: 'updated', auditable: $branch, asSystem: true);
        AuditLogService::log(action: 'updated', auditable: $branch, asSystem: true);

        $this->assertSame(1, $lookups);
        $this->assertSame($branch->id, AuditLog::query()->whereKey($first->id)->value('branch_id'));
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

        AuditLogService::forgetColumns();
    }

    private function migration(string $file): Migration
    {
        return require database_path('migrations/'.$file);
    }
}
