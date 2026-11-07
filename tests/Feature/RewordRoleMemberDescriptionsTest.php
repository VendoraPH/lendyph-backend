<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The admin and loan_officer descriptions say "members", not "borrowers": the
 * seeder for a fresh database, and the data fix (command and migration) for a
 * deployment still holding the old seeded text.
 */
class RewordRoleMemberDescriptionsTest extends TestCase
{
    use SetupLendyPH;

    private const MIGRATION = 'migrations/2026_11_07_090000_reword_role_member_descriptions.php';

    private const OLD_ADMIN = 'Client-side administrator — every operational permission for the lending organization (borrowers, loans, payments, staff, reports, settings).';

    private const NEW_ADMIN = 'Client-side administrator — every operational permission for the lending organization (members, loans, payments, staff, reports, settings).';

    private const OLD_OFFICER = 'Creates and processes loan applications; manages borrowers and share capital.';

    private const NEW_OFFICER = 'Creates and processes loan applications; manages members and share capital.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_the_seeder_describes_the_roles_with_members(): void
    {
        $this->assertSame(self::NEW_ADMIN, $this->description('admin'));
        $this->assertSame(self::NEW_OFFICER, $this->description('loan_officer'));
        $this->assertSame(0, Role::query()->where('description', 'like', '%borrower%')->count());
    }

    public function test_a_dry_run_lists_what_would_change_and_writes_nothing(): void
    {
        $this->restoreOldText();
        $before = $this->snapshot();

        $this->artisan('roles:reword-member-descriptions', ['--dry-run' => true])
            ->expectsOutputToContain('admin (id '.$this->id('admin').'): would reword')
            ->expectsOutputToContain('loan_officer (id '.$this->id('loan_officer').'): would reword')
            ->expectsOutputToContain('Would reword 2 role description(s); 0 left as edited.')
            ->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_it_rewords_only_the_old_seeded_text_and_records_each_change_as_a_system_change(): void
    {
        $this->restoreOldText();
        $edited = self::OLD_OFFICER.' Also handles walk-ins.';
        DB::table('roles')->where('name', 'loan_officer')->update(['description' => $edited]);
        $auditRows = AuditLog::count();

        $this->artisan('roles:reword-member-descriptions')
            ->expectsOutputToContain('loan_officer (id '.$this->id('loan_officer').') left as it is: its description was edited')
            ->expectsOutputToContain('Reworded 1 role description(s); 1 left as edited.')
            ->assertSuccessful();

        $this->assertSame(self::NEW_ADMIN, $this->description('admin'));
        $this->assertSame($edited, $this->description('loan_officer'));

        // One row, written by the fix: no second row from the Auditable trait.
        $this->assertSame($auditRows + 1, AuditLog::count());
        $row = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertNull($row->user_id);
        $this->assertNull($row->ip_address);
        $this->assertSame('updated', $row->action);
        $this->assertSame((new Role)->getMorphClass(), $row->auditable_type);
        $this->assertSame($this->id('admin'), (int) $row->auditable_id);
        $this->assertSame(['description' => self::OLD_ADMIN], $row->old_values);
        $this->assertSame(['description' => self::NEW_ADMIN], $row->new_values);
        $this->assertStringStartsWith('System correction: ', $row->description);
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->restoreOldText();
        $this->artisan('roles:reword-member-descriptions')->assertSuccessful();
        $after = $this->snapshot();

        $this->artisan('roles:reword-member-descriptions')
            ->expectsOutputToContain('Reworded 0 role description(s); 0 left as edited.')
            ->assertSuccessful();

        $this->assertSame($after, $this->snapshot());
    }

    public function test_the_migration_applies_the_same_fix(): void
    {
        $this->restoreOldText();
        $auditRows = AuditLog::count();

        $this->migration()->up();

        $this->assertSame(self::NEW_ADMIN, $this->description('admin'));
        $this->assertSame(self::NEW_OFFICER, $this->description('loan_officer'));
        $this->assertSame($auditRows + 2, AuditLog::count());
        $this->assertSame(2, AuditLog::query()->whereNull('user_id')->where('description', 'like', 'System correction: %')->count());

        $after = $this->snapshot();
        $this->migration()->up();
        $this->assertSame($after, $this->snapshot());
    }

    private function restoreOldText(): void
    {
        DB::table('roles')->where('name', 'admin')->update(['description' => self::OLD_ADMIN]);
        DB::table('roles')->where('name', 'loan_officer')->update(['description' => self::OLD_OFFICER]);
    }

    private function description(string $key): ?string
    {
        return DB::table('roles')->where('name', $key)->value('description');
    }

    private function id(string $key): int
    {
        return (int) DB::table('roles')->where('name', $key)->value('id');
    }

    /**
     * @return array{roles: array<int, string|null>, audit_rows: int}
     */
    private function snapshot(): array
    {
        return [
            'roles' => DB::table('roles')->orderBy('id')->pluck('description', 'id')->all(),
            'audit_rows' => AuditLog::count(),
        ];
    }

    private function migration(): Migration
    {
        return require database_path(self::MIGRATION);
    }
}
