<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The audit_logs.user_id foreign key migration, both ways.
 */
class AuditLogUserRestrictMigrationTest extends TestCase
{
    /**
     * Runs the migration's down() and up(), which drop and add a foreign key.
     * MySQL implicitly commits the open transaction on any DDL, so a rollback
     * wouldn't undo it; this needs the real `migrate:fresh` before each test
     * that Tests\TestCase gives when this is false.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_02_120000_restrict_user_delete_on_audit_logs.php');
    }

    private function deleteRule(): ?string
    {
        return DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'audit_logs')
            ->where('REFERENCED_TABLE_NAME', 'users')
            ->value('DELETE_RULE');
    }

    public function test_up_restricts_the_delete_and_down_restores_set_null(): void
    {
        $this->assertSame('RESTRICT', $this->deleteRule());

        $migration = $this->migration();
        $migration->down();
        $this->assertSame('SET NULL', $this->deleteRule());

        $migration->up();
        $this->assertSame('RESTRICT', $this->deleteRule());
    }
}
