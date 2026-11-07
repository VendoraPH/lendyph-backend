<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reword the admin and loan_officer descriptions from "borrowers" to
 * "members" on a deployment that still holds the text the seeder wrote before
 * 2026-11-07.
 *
 * A role is reworded only when its description still equals the old seeded
 * text exactly; one somebody edited is left alone and listed with the reason.
 * A reworded role no longer matches, so running it again changes nothing.
 *
 * Each change writes one audit row as a system change (no user, no address):
 * an `updated` row with the old and new description, the row the Auditable
 * trait would write for a role edit. The update goes through the query
 * builder, so the trait writes no second row.
 *
 * Used by `roles:reword-member-descriptions` (with `--dry-run` to preview a
 * database) and by the 2026_11_07_090000 migration that applies it on deploy.
 */
class RoleMemberDescriptionReword
{
    /**
     * Role key => [old seeded text, new text].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const REWORDS = [
        'admin' => [
            'Client-side administrator — every operational permission for the lending organization (borrowers, loans, payments, staff, reports, settings).',
            'Client-side administrator — every operational permission for the lending organization (members, loans, payments, staff, reports, settings).',
        ],
        'loan_officer' => [
            'Creates and processes loan applications; manages borrowers and share capital.',
            'Creates and processes loan applications; manages members and share capital.',
        ],
    ];

    /**
     * Polymorphic rows store the full class name; there is no morph map.
     */
    private const AUDITABLE_TYPE = 'App\Models\Role';

    /**
     * Reword every role that still holds the old text, or with `$dryRun` only
     * say which.
     *
     * @return array{updated: list<array{id: int, name: string}>, skipped: list<array{id: int, name: string, reason: string}>}
     */
    public function run(bool $dryRun): array
    {
        $result = ['updated' => [], 'skipped' => []];

        $roles = DB::table('roles')
            ->whereIn('name', array_keys(self::REWORDS))
            ->orderBy('id')
            ->get(['id', 'name', 'display_name', 'description']);

        foreach ($roles as $role) {
            [$old, $new] = self::REWORDS[$role->name];

            if ($role->description === $new) {
                continue;
            }

            if ($role->description !== $old) {
                $result['skipped'][] = ['id' => (int) $role->id, 'name' => $role->name, 'reason' => 'its description was edited'];

                continue;
            }

            if ($dryRun || $this->write($role, $old, $new)) {
                $result['updated'][] = ['id' => (int) $role->id, 'name' => $role->name];
            }
        }

        if (! $dryRun && $result['updated'] !== []) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $result;
    }

    /**
     * The one line the migration prints to the deploy log.
     *
     * @param  array{updated: list<mixed>, skipped: list<mixed>}  $result
     */
    public static function summary(array $result): string
    {
        return sprintf(
            'role member descriptions: %d reworded, %d left as edited',
            count($result['updated']),
            count($result['skipped']),
        );
    }

    /**
     * Reword one role and record it. Guarded on the description still being
     * the old text, so a role edited since it was read is left as it now is.
     */
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
}
