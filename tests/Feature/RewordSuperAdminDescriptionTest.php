<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Super Admin description has no developer words: the seeder for a fresh
 * database, and the data fix (command and migration) for a deployment still
 * holding the old seeded text.
 */
class RewordSuperAdminDescriptionTest extends TestCase
{
    use SetupLendyPH;

    private const MIGRATION = 'migrations/2026_11_07_100000_reword_super_admin_description.php';

    private const OLD = 'Developer-side super administrator — every permission plus Gate::before bypass. Reserved for the platform team, not client staff.';

    private const NEW = 'Platform super administrator — holds every permission. Reserved for the platform team, not client staff.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_seeder_has_no_developer_words(): void
    {
        $this->assertSame(self::NEW, $this->description());
        $this->assertSame(0, Role::query()->where('description', 'like', '%Gate::%')->count());
    }

    public function test_a_dry_run_lists_it_and_writes_nothing(): void
    {
        $this->restoreOldText();
        $auditRows = AuditLog::count();

        $this->artisan('roles:reword-member-descriptions', ['--dry-run' => true])
            ->expectsOutputToContain('super_admin (id '.$this->id().'): would reword')
            ->assertSuccessful();

        $this->assertSame(self::OLD, $this->description());
        $this->assertSame($auditRows, AuditLog::count());
    }

    public function test_the_command_rewords_the_old_text_once_and_records_a_system_change(): void
    {
        $this->restoreOldText();
        $auditRows = AuditLog::count();

        $this->artisan('roles:reword-member-descriptions')
            ->expectsOutputToContain('super_admin (id '.$this->id().'): reworded')
            ->assertSuccessful();

        $this->assertSame(self::NEW, $this->description());
        $this->assertSame($auditRows + 1, AuditLog::count());

        $row = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertNull($row->user_id);
        $this->assertSame('updated', $row->action);
        $this->assertSame(['description' => self::OLD], $row->old_values);
        $this->assertSame(['description' => self::NEW], $row->new_values);
        $this->assertStringContainsString('(super_admin)', $row->description);
        $this->assertStringNotContainsString('borrowers', $row->description);

        $this->artisan('roles:reword-member-descriptions')->assertSuccessful();
        $this->assertSame($auditRows + 1, AuditLog::count());
    }

    public function test_an_edited_description_is_left_alone_and_listed(): void
    {
        DB::table('roles')->where('name', 'super_admin')->update(['description' => 'Our own words.']);
        $auditRows = AuditLog::count();

        $this->artisan('roles:reword-member-descriptions')
            ->expectsOutputToContain('super_admin (id '.$this->id().') left as it is: its description was edited')
            ->assertSuccessful();

        $this->migration()->up();

        $this->assertSame('Our own words.', $this->description());
        $this->assertSame($auditRows, AuditLog::count());
    }

    public function test_the_migration_and_the_command_reach_the_same_outcome(): void
    {
        $this->restoreOldText();
        $fromId = (int) AuditLog::max('id');

        DB::beginTransaction();
        $this->artisan('roles:reword-member-descriptions')->assertSuccessful();
        $byCommand = $this->outcome($fromId);
        DB::rollBack();

        $this->migration()->up();
        $byMigration = $this->outcome($fromId);

        $this->assertSame($byCommand, $byMigration);
        $this->assertSame(self::NEW, $byMigration['description']);
        $this->assertCount(1, $byMigration['audit_rows']);

        $this->migration()->up();
        $this->assertSame($byMigration, $this->outcome($fromId));
    }

    public function test_the_migration_depends_on_no_application_class(): void
    {
        $source = (string) file_get_contents(database_path(self::MIGRATION));

        $this->assertDoesNotMatchRegularExpression('/^use App\\\\/m', $source);
    }

    /**
     * @return array{description: string|null, audit_rows: list<array<string, mixed>>}
     */
    private function outcome(int $fromId): array
    {
        return [
            'description' => $this->description(),
            'audit_rows' => AuditLog::query()->where('id', '>', $fromId)->orderBy('id')->get()
                ->map(fn (AuditLog $row): array => $row->only(['user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'ip_address', 'user_agent', 'description']))
                ->all(),
        ];
    }

    private function restoreOldText(): void
    {
        DB::table('roles')->where('name', 'super_admin')->where('guard_name', 'web')->update(['description' => self::OLD]);
    }

    private function description(): ?string
    {
        return DB::table('roles')->where('name', 'super_admin')->where('guard_name', 'web')->value('description');
    }

    private function id(): int
    {
        return (int) DB::table('roles')->where('name', 'super_admin')->where('guard_name', 'web')->value('id');
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }
}
