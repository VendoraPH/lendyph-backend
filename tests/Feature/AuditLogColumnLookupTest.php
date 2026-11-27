<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Services\AuditLogService;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AuditLogService looks up the audit_logs columns only during a migrate run,
 * and then once until the next migration ends. Ordinary writes never look.
 * See AuditLogUpgradeFromMainTest for the upgrade itself.
 */
class AuditLogColumnLookupTest extends TestCase
{
    private int $lookups = 0;

    protected function setUp(): void
    {
        parent::setUp();

        DB::listen(function ($query): void {
            if (str_contains(strtolower($query->sql), 'information_schema.columns')) {
                $this->lookups++;
            }
        });
    }

    protected function tearDown(): void
    {
        AuditLogService::migrationsEnded();

        parent::tearDown();
    }

    public function test_outside_a_migrate_run_it_writes_every_column_without_a_lookup(): void
    {
        $branch = Branch::query()->firstOrFail();

        $first = AuditLogService::log(action: 'updated', auditable: $branch, asSystem: true);
        AuditLogService::log(action: 'updated', auditable: $branch, asSystem: true);

        $this->assertSame(0, $this->lookups);
        $this->assertSame($branch->id, AuditLog::query()->whereKey($first->id)->value('branch_id'));
    }

    public function test_during_a_migrate_run_it_looks_up_the_columns_once_for_two_writes(): void
    {
        $branch = Branch::query()->firstOrFail();
        event(new MigrationsStarted('up'));

        $first = AuditLogService::log(action: 'updated', auditable: $branch, asSystem: true);
        AuditLogService::log(action: 'updated', auditable: $branch, asSystem: true);

        $this->assertSame(1, $this->lookups);
        $this->assertSame($branch->id, AuditLog::query()->whereKey($first->id)->value('branch_id'));
    }
}
