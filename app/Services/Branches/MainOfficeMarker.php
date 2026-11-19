<?php

namespace App\Services\Branches;

use App\Services\DefaultBranchAssignment;
use Illuminate\Support\Facades\DB;

/**
 * Data fix 1 (Phase 6 plan §5, multi-branch decision 4): set the main-office
 * flag on the one branch today's main-branch rule finds
 * (DefaultBranchAssignment::byTodaysRule(): the only active branch, else the
 * one active branch named as the main branch).
 *
 * - A branch already flagged: nothing to do.
 * - None or several found: nothing is written, and the active branches are
 *   listed for staff to pick in Settings > Branches.
 * - Otherwise the flag is set in one transaction, guarded on the branch still
 *   being active and unflagged and on no branch being flagged meanwhile (the
 *   unique `main_office_key` holds that too), with one `updated` audit row
 *   as a system change (no user, no address). Written with the query builder,
 *   so the Auditable trait writes no second row. A second run finds the flag
 *   and changes nothing.
 *
 * Used by `branches:mark-main-office` (`--dry-run` previews a database). The
 * 2026_11_19_310200 migration runs the same fix on deploy, written out with
 * the query builder alone; MarkMainOfficeTest holds the two to one outcome.
 */
class MainOfficeMarker
{
    /** Polymorphic rows store the full class name; there is no morph map. */
    private const BRANCH_TYPE = 'App\Models\Branch';

    /**
     * @return array{already: string|null, branch: array{id: int, name: string, code: string, basis: string}|null, reason: string|null, active: list<array{id: int, name: string, code: string}>, marked: bool}
     */
    public function run(bool $dryRun): array
    {
        $result = ['already' => null, 'branch' => null, 'reason' => null, 'active' => [], 'marked' => false];

        $flagged = DB::table('branches')->where('is_main_office', true)->first(['name']);

        if ($flagged !== null) {
            return [...$result, 'already' => (string) $flagged->name];
        }

        $found = DefaultBranchAssignment::byTodaysRule();

        if ($found['branch'] === null) {
            return [
                ...$result,
                'reason' => $found['reason'],
                'active' => DB::table('branches')->where('status', 'active')->orderBy('id')->get(['id', 'name', 'code'])
                    ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name, 'code' => (string) $row->code])
                    ->all(),
            ];
        }

        $result['branch'] = $found['branch'];

        if ($dryRun) {
            return $result;
        }

        $marked = $this->write($found['branch']);

        return [...$result, 'marked' => $marked, 'reason' => $marked ? null : "{$found['branch']['name']} changed while the fix ran"];
    }

    /**
     * @param  array{id: int, name: string, code: string, basis: string}  $branch
     */
    private function write(array $branch): bool
    {
        return DB::transaction(function () use ($branch): bool {
            if (DB::table('branches')->where('is_main_office', true)->lockForUpdate()->exists()) {
                return false;
            }

            $now = now();
            $updated = DB::table('branches')
                ->where('id', $branch['id'])
                ->where('status', 'active')
                ->where('is_main_office', false)
                ->update(['is_main_office' => true, 'updated_at' => $now]);

            if ($updated === 0) {
                return false;
            }

            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'updated',
                'auditable_type' => self::BRANCH_TYPE,
                'auditable_id' => $branch['id'],
                'old_values' => json_encode(['is_main_office' => false]),
                'new_values' => json_encode(['is_main_office' => true]),
                'ip_address' => null,
                'user_agent' => null,
                'description' => sprintf(
                    'System correction: Branch %s (%s) marked as the main office (%s).',
                    json_encode($branch['name'], JSON_UNESCAPED_UNICODE),
                    $branch['code'],
                    $branch['basis'],
                ),
                'created_at' => $now,
            ]);

            return true;
        });
    }
}
