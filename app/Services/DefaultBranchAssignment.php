<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Put active staff who have no branch in the main branch (owner answer,
 * 2026-11-11).
 *
 * ## Who
 *
 * An active user (`status` active) with no branch at all: no `branch_user`
 * row and no legacy `users.branch_id`, so BranchAccess shows them nothing.
 * Left alone:
 * - admin and super_admin, whatever their role rows say;
 * - anyone holding `branches:view_all` through an active role or directly,
 *   the same reading as User::activeRoles() (a deactivated role grants
 *   nothing, so its users are assigned);
 * - inactive users, and anyone with a branch, even an inactive one.
 *
 * ## Which branch is "main"
 *
 * The branches table has no head-office flag, so:
 * 1. the only active branch, when there is just one;
 * 2. else the one active branch marked as the main branch: its code is MAIN,
 *    HO or HQ, or its name is Main, Main Branch, Main Office, Head Office or
 *    Headquarters (case and outer spaces ignored; BranchSeeder writes
 *    "Main Branch", MAIN);
 * 3. else (none, or several marked) nobody is assigned, and every user it
 *    would have assigned is listed as skipped.
 *
 * ## Writes
 *
 * Each user is written in its own transaction, guarded on still having no
 * branch, so a user given one while the fix ran is left as they now are,
 * listed and logged. The user's `branch_id` and the `branch_user` row are set
 * together (the column always names one of the pivot's branches), and one
 * `branches_changed` audit row is written as a system change (no user, no
 * address), the row UserController writes for a branch change. The update
 * goes through the query builder, so the Auditable trait writes no second
 * row. An assigned user no longer matches, so a second run changes nothing.
 *
 * Used by `users:assign-default-branch` (with `--dry-run` to preview a
 * database). The 2026_11_11_620000 migration does the same on deploy, written
 * out with the query builder so that it depends on no application class;
 * keep them in step (AssignDefaultBranchTest compares them).
 */
class DefaultBranchAssignment
{
    /** Branch codes that mark the main branch, upper case. */
    public const MAIN_CODES = ['MAIN', 'HO', 'HQ'];

    /** Branch names that mark the main branch, lower case. */
    public const MAIN_NAMES = ['main', 'main branch', 'main office', 'head office', 'headquarters'];

    /** Roles left alone whatever their rows say (Role::PROTECTED_KEYS). */
    private const LEFT_ALONE_ROLES = ['admin', 'super_admin'];

    /**
     * Polymorphic rows store the full class name; there is no morph map.
     */
    private const USER_TYPE = 'App\Models\User';

    /**
     * Assign every branchless staff user to the main branch, or with `$dryRun`
     * only say who.
     *
     * @return array{branch: array{id: int, name: string, code: string, basis: string}|null, no_branch_reason: string|null, assigned: list<array{id: int, username: string}>, skipped: list<array{id: int, username: string, reason: string}>}
     */
    public function run(bool $dryRun): array
    {
        $main = self::mainBranch();
        $result = [
            'branch' => $main['branch'],
            'no_branch_reason' => $main['reason'],
            'assigned' => [],
            'skipped' => [],
        ];

        foreach (self::candidates()->get(['users.id', 'users.username']) as $user) {
            $entry = ['id' => (int) $user->id, 'username' => (string) $user->username];

            if ($main['branch'] === null) {
                $result['skipped'][] = [...$entry, 'reason' => 'no main branch'];

                continue;
            }

            if ($dryRun || $this->write($user, $main['branch'])) {
                $result['assigned'][] = $entry;

                continue;
            }

            $result['skipped'][] = [...$entry, 'reason' => 'given a branch while the fix ran'];
            Log::warning('A user was given a branch while the fix ran; they were left as they are.', ['user' => $entry['username'], 'id' => $entry['id']]);
        }

        return $result;
    }

    /**
     * The one line the migration prints to the deploy log.
     *
     * @param  array{assigned: list<mixed>, skipped: list<mixed>}  $result
     */
    public static function summary(array $result): string
    {
        return sprintf(
            'branchless staff: %d assigned to the main branch, %d skipped',
            count($result['assigned']),
            count($result['skipped']),
        );
    }

    /**
     * The main branch, or why there is none.
     *
     * @return array{branch: array{id: int, name: string, code: string, basis: string}|null, reason: string|null}
     */
    public static function mainBranch(): array
    {
        $active = DB::table('branches')->where('is_active', true)->orderBy('id')->get(['id', 'name', 'code']);
        $describe = fn (object $branch, string $basis): array => [
            'id' => (int) $branch->id,
            'name' => (string) $branch->name,
            'code' => (string) $branch->code,
            'basis' => $basis,
        ];

        if ($active->isEmpty()) {
            return ['branch' => null, 'reason' => 'there is no active branch'];
        }

        if ($active->count() === 1) {
            return ['branch' => $describe($active->first(), 'the only active branch'), 'reason' => null];
        }

        $marked = $active->filter(fn (object $branch): bool => in_array(mb_strtoupper(trim((string) $branch->code)), self::MAIN_CODES, true)
            || in_array(mb_strtolower(trim((string) $branch->name)), self::MAIN_NAMES, true))->values();

        return match ($marked->count()) {
            1 => ['branch' => $describe($marked->first(), 'the branch marked as the main branch'), 'reason' => null],
            0 => ['branch' => null, 'reason' => "{$active->count()} active branches and none is marked as the main branch"],
            default => ['branch' => null, 'reason' => "{$marked->count()} active branches are marked as the main branch"],
        };
    }

    /**
     * Active users with no branch and no `branches:view_all`, admin and
     * super_admin excluded, by id.
     */
    private static function candidates(): Builder
    {
        return DB::table('users')
            ->where('users.status', 'active')
            ->whereNull('users.branch_id')
            ->whereNotExists(fn (Builder $pivot) => $pivot->select(DB::raw(1))
                ->from('branch_user')
                ->whereColumn('branch_user.user_id', 'users.id'))
            ->whereNotExists(fn (Builder $roles) => $roles->select(DB::raw(1))
                ->from('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->where('model_has_roles.model_type', self::USER_TYPE)
                ->where(fn (Builder $role) => $role
                    ->whereIn('roles.name', self::LEFT_ALONE_ROLES)
                    ->orWhere(fn (Builder $granting) => $granting
                        ->where('roles.is_active', true)
                        ->whereExists(fn (Builder $grants) => $grants->select(DB::raw(1))
                            ->from('role_has_permissions')
                            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                            ->whereColumn('role_has_permissions.role_id', 'roles.id')
                            ->where('permissions.name', BranchAccess::VIEW_ALL)
                            ->where('permissions.guard_name', 'web')))))
            ->whereNotExists(fn (Builder $direct) => $direct->select(DB::raw(1))
                ->from('model_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
                ->whereColumn('model_has_permissions.model_id', 'users.id')
                ->where('model_has_permissions.model_type', self::USER_TYPE)
                ->where('permissions.name', BranchAccess::VIEW_ALL)
                ->where('permissions.guard_name', 'web'))
            ->orderBy('users.id');
    }

    /**
     * Assign one user and record it. Guarded on the user still being active
     * with no branch, so one changed since it was read is left as it now is.
     *
     * @param  array{id: int, name: string}  $branch
     */
    private function write(object $user, array $branch): bool
    {
        return DB::transaction(function () use ($user, $branch): bool {
            $now = now();

            $updated = DB::table('users')
                ->where('id', $user->id)
                ->where('status', 'active')
                ->whereNull('branch_id')
                ->whereNotExists(fn (Builder $pivot) => $pivot->select(DB::raw(1))
                    ->from('branch_user')
                    ->whereColumn('branch_user.user_id', 'users.id'))
                ->update(['branch_id' => $branch['id'], 'updated_at' => $now]);

            if ($updated === 0) {
                return false;
            }

            DB::table('branch_user')->insertOrIgnore(['user_id' => $user->id, 'branch_id' => $branch['id']]);

            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'branches_changed',
                'auditable_type' => self::USER_TYPE,
                'auditable_id' => $user->id,
                'old_values' => json_encode(['branch_ids' => []]),
                'new_values' => json_encode(['branch_ids' => [$branch['id']]]),
                'ip_address' => null,
                'user_agent' => null,
                'description' => sprintf(
                    'System correction: Branch assignment for %s changed from none to %s (staff with no branch join the main branch).',
                    $user->username,
                    $branch['name'],
                ),
                'created_at' => $now,
            ]);

            return true;
        });
    }
}
