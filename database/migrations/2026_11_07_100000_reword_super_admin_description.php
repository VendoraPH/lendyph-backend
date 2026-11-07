<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * Spelled out rather than read off RoleMemberDescriptionReword and the Role
     * model: this migration has to write on its last deployment exactly what it
     * wrote on its first, whatever those classes become. Polymorphic rows store
     * the full class name; there is no morph map.
     */
    private const OLD = 'Developer-side super administrator — every permission plus Gate::before bypass. Reserved for the platform team, not client staff.';

    private const NEW = 'Platform super administrator — holds every permission. Reserved for the platform team, not client staff.';

    private const AUDITABLE_TYPE = 'App\Models\Role';

    /**
     * Take the developer words out of the super_admin description where it
     * still holds the old seeded text.
     *
     * The same selection, skips and audit rows as RoleMemberDescriptionReword,
     * whose docblock explains them, written here with the query builder alone.
     * RewordSuperAdminDescriptionTest holds the two to the same outcome.
     * `php artisan roles:reword-member-descriptions --dry-run` shows what this
     * will change on a database before it is deployed there.
     *
     * Only the `web` role still holding the old text is written, guarded on
     * that text, so an edited description (before or during the run) is left
     * alone and logged, and a second run changes nothing. The change writes one
     * audit row as a system change (no user). On a fresh database the role does
     * not exist yet and the seeder writes the new text, so this does nothing.
     *
     * The summary line is printed as well as logged, so the count shows in the
     * deploy log. Not under the test suite, which runs every migration on a
     * fresh database for each worker and would print it each time.
     */
    public function up(): void
    {
        $role = DB::table('roles')
            ->where('name', 'super_admin')
            ->where('guard_name', 'web')
            ->first(['id', 'name', 'display_name', 'description']);

        if ($role === null || $role->description === self::NEW) {
            return;
        }

        $updated = $role->description === self::OLD && $this->write($role);

        if ($updated) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } else {
            Log::warning('The super_admin description was edited, so it was left as it is.', ['id' => (int) $role->id]);
        }

        $summary = sprintf('super_admin description: %d reworded, %d left as it is', (int) $updated, (int) ! $updated);

        Log::info($summary);

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    /**
     * Nothing to undo: the old text named code, not anything staff use, and
     * audit rows are never deleted.
     */
    public function down(): void {}

    private function write(object $role): bool
    {
        return DB::transaction(function () use ($role): bool {
            $now = now();

            $updated = DB::table('roles')
                ->where('id', $role->id)
                ->where('description', self::OLD)
                ->update(['description' => self::NEW, 'updated_at' => $now]);

            if ($updated === 0) {
                return false;
            }

            $label = filled($role->display_name) ? trim((string) $role->display_name) : Str::headline((string) $role->name);

            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'updated',
                'auditable_type' => self::AUDITABLE_TYPE,
                'auditable_id' => $role->id,
                'old_values' => json_encode(['description' => self::OLD], JSON_UNESCAPED_UNICODE),
                'new_values' => json_encode(['description' => self::NEW], JSON_UNESCAPED_UNICODE),
                'ip_address' => null,
                'user_agent' => null,
                'description' => sprintf(
                    'System correction: Role %s (%s) description no longer uses developer wording.',
                    json_encode($label, JSON_UNESCAPED_UNICODE),
                    $role->name,
                ),
                'created_at' => $now,
            ]);

            return true;
        });
    }
};
