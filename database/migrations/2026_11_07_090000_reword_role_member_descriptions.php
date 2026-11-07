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
     *
     * Role key => [old seeded text, new text].
     */
    private const REWORDS = [
        'admin' => [
            'Client-side administrator — every operational permission for the lending organization (borrowers, loans, payments, staff, reports, settings).',
            'Client-side administrator — every operational permission for the lending organization (members, loans, payments, staff, reports, settings).',
        ],
        'loan_officer' => [
            'Creates and processes loan applications; manages borrowers and share capital.',
            'Creates and processes loan applications; manages members and share capital.',
        ],
    ];

    private const AUDITABLE_TYPE = 'App\Models\Role';

    /**
     * Reword the admin and loan_officer descriptions from "borrowers" to
     * "members" where they still hold the old seeded text.
     *
     * The same selection, skips and audit rows as RoleMemberDescriptionReword,
     * whose docblock explains them, written here with the query builder alone.
     * RewordRoleMemberDescriptionsTest holds the two to the same outcome.
     * `php artisan roles:reword-member-descriptions --dry-run` shows what this
     * will change on a database before it is deployed there.
     *
     * Only `web` roles still holding the old text are written, guarded on that
     * text, so an edited description (before or during the run) is left alone
     * and logged, and a second run changes nothing. Each change writes one
     * audit row as a system change (no user). On a fresh database the roles do
     * not exist yet and the seeder writes the new text, so this does nothing.
     *
     * The summary line is printed as well as logged, so the count shows in the
     * deploy log. Not under the test suite, which runs every migration on a
     * fresh database for each worker and would print it each time.
     */
    public function up(): void
    {
        $result = ['updated' => [], 'skipped' => []];

        $roles = DB::table('roles')
            ->whereIn('name', array_keys(self::REWORDS))
            ->where('guard_name', 'web')
            ->orderBy('id')
            ->get(['id', 'name', 'display_name', 'description']);

        foreach ($roles as $role) {
            [$old, $new] = self::REWORDS[$role->name];

            if ($role->description === $new) {
                continue;
            }

            $entry = ['id' => (int) $role->id, 'name' => $role->name];

            if ($role->description !== $old) {
                $result['skipped'][] = [...$entry, 'reason' => 'its description was edited'];

                continue;
            }

            if ($this->write($role, $old, $new)) {
                $result['updated'][] = $entry;

                continue;
            }

            $result['skipped'][] = [...$entry, 'reason' => 'changed while the fix ran'];
            Log::warning('A role description changed while the fix ran; it was left as it is.', ['role' => $role->name, 'id' => (int) $role->id]);
        }

        if ($result['updated'] !== []) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $summary = sprintf(
            'role member descriptions: %d reworded, %d left as edited',
            count($result['updated']),
            count($result['skipped']),
        );

        Log::info($summary, $result);

        if ($result['skipped'] !== []) {
            Log::warning('Role descriptions that were edited were left unchanged.', ['roles' => $result['skipped']]);
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    /**
     * Nothing to undo: the old text said "borrowers", which the app no longer
     * uses, and audit rows are never deleted.
     */
    public function down(): void {}

    private function write(object $role, string $old, string $new): bool
    {
        return DB::transaction(function () use ($role, $old, $new): bool {
            $now = now();

            $updated = DB::table('roles')
                ->where('id', $role->id)
                ->where('description', $old)
                ->update(['description' => $new, 'updated_at' => $now]);

            if ($updated === 0) {
                return false;
            }

            $label = filled($role->display_name) ? trim((string) $role->display_name) : Str::headline((string) $role->name);

            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'updated',
                'auditable_type' => self::AUDITABLE_TYPE,
                'auditable_id' => $role->id,
                'old_values' => json_encode(['description' => $old], JSON_UNESCAPED_UNICODE),
                'new_values' => json_encode(['description' => $new], JSON_UNESCAPED_UNICODE),
                'ip_address' => null,
                'user_agent' => null,
                'description' => sprintf(
                    'System correction: Role %s (%s) description now says "members" instead of "borrowers".',
                    json_encode($label, JSON_UNESCAPED_UNICODE),
                    $role->name,
                ),
                'created_at' => $now,
            ]);

            return true;
        });
    }
};
