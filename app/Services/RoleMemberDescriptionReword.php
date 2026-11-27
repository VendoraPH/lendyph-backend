<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reword the admin and loan_officer descriptions from "borrowers" to
 * "members", and take the developer words out of the super_admin one, on a
 * deployment that still holds the text the seeder wrote before 2026-11-07.
 *
 * Only `web` guard roles are read. A role is reworded only when its
 * description still equals the old seeded text exactly; one somebody edited,
 * before or while the fix ran, is left alone and listed with the reason.
 * A reworded role no longer matches, so running it again changes nothing.
 *
 * Each change writes one audit row as a system change (no user, no address):
 * an `updated` row with the old and new description, the row the Auditable
 * trait would write for a role edit. The update goes through the query
 * builder, so the trait writes no second row.
 *
 * Used by `roles:reword-member-descriptions` (with `--dry-run` to preview a
 * database). The 2026_11_07_090000 and 2026_11_07_100000 migrations do the same
 * on deploy, written out with the query builder so that they depend on no
 * application class; keep them in step (RewordRoleMemberDescriptionsTest and
 * RewordSuperAdminDescriptionTest compare them).
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
        'super_admin' => [
            'Developer-side super administrator — every permission plus Gate::before bypass. Reserved for the platform team, not client staff.',
            'Platform super administrator — holds every permission. Reserved for the platform team, not client staff.',
        ],
    ];

    /**
     * What the audit row says the correction was, by role key.
     *
     * @var array<string, string>
     */
    private const CORRECTIONS = [
        'admin' => 'now says "members" instead of "borrowers"',
        'loan_officer' => 'now says "members" instead of "borrowers"',
        'super_admin' => 'no longer uses developer wording',
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
            ->where('guard_name', 'web')
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

                continue;
            }

            $result['skipped'][] = ['id' => (int) $role->id, 'name' => $role->name, 'reason' => 'changed while the fix ran'];
            Log::warning('A role description changed while the fix ran; it was left as it is.', ['role' => $role->name, 'id' => (int) $role->id]);
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
            'role member descriptions: %d reworded, %d left as they are',
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
                    'System correction: Role %s (%s) description %s.',
                    json_encode($label, JSON_UNESCAPED_UNICODE),
                    $role->name,
                    self::CORRECTIONS[$role->name],
                ),
                'created_at' => $now,
            ]);

            return true;
        });
    }
}
