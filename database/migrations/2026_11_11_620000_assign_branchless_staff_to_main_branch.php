<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * Spelled out rather than read off DefaultBranchAssignment and the models:
     * this migration has to write on its last deployment exactly what it wrote
     * on its first, whatever those classes become. Polymorphic rows store the
     * full class name; there is no morph map.
     */
    /** Lower case with no spaces; a code alone never marks the main branch. */
    private const MAIN_NAMES = ['main', 'mainbranch', 'mainoffice', 'headoffice', 'headquarters'];

    private const LEFT_ALONE_ROLES = ['admin', 'super_admin'];

    private const VIEW_ALL = 'branches:view_all';

    private const USER_TYPE = 'App\Models\User';

    /**
     * Put active staff with no branch and no `branches:view_all` in the main
     * branch (owner answer, 2026-11-11).
     *
     * The same selection, main-branch rule, skips and audit rows as
     * DefaultBranchAssignment, whose docblock explains them, written here with
     * the query builder alone. AssignDefaultBranchTest holds the two to the
     * same outcome. `php artisan users:assign-default-branch --dry-run` shows
     * what this will change on a database before it is deployed there.
     *
     * With no clear main branch nobody is assigned and the users are logged
     * as skipped. Each user is guarded on still having no branch, so a second
     * run changes nothing. Each change writes one audit row as a system change
     * (no user). On a fresh database there are no users yet, so this does
     * nothing.
     *
     * The summary line is printed as well as logged, so the count shows in the
     * deploy log. Not under the test suite, which runs every migration on a
     * fresh database for each worker and would print it each time.
     */
    public function up(): void
    {
        $main = $this->mainBranch();
        $result = ['assigned' => [], 'skipped' => []];

        foreach ($this->candidates()->get(['users.id', 'users.username']) as $user) {
            $entry = ['id' => (int) $user->id, 'username' => (string) $user->username];

            if ($main['branch'] === null) {
                $result['skipped'][] = [...$entry, 'reason' => 'no main branch'];

                continue;
            }

            if ($this->write($user, $main['branch'])) {
                $result['assigned'][] = $entry;

                continue;
            }

            $result['skipped'][] = [...$entry, 'reason' => 'given a branch while the fix ran'];
            Log::warning('A user was given a branch while the fix ran; they were left as they are.', ['user' => $entry['username'], 'id' => $entry['id']]);
        }

        $summary = sprintf(
            'branchless staff: %d assigned to the main branch, %d skipped',
            count($result['assigned']),
            count($result['skipped']),
        );

        Log::info($summary, [...$result, 'branch' => $main['branch'], 'no_branch_reason' => $main['reason']]);

        if ($result['skipped'] !== []) {
            Log::warning('Staff with no branch were left unassigned; assign them in Settings > Users.', ['reason' => $main['reason'], 'users' => $result['skipped']]);
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    /**
     * Nothing to undo: an assignment can be changed in Settings > Users, and
     * audit rows are never deleted.
     */
    public function down(): void {}

    /**
     * @return array{branch: array{id: int, name: string}|null, reason: string|null}
     */
    private function mainBranch(): array
    {
        $active = DB::table('branches')->where('is_active', true)->orderBy('id')->get(['id', 'name']);

        if ($active->isEmpty()) {
            return ['branch' => null, 'reason' => 'there is no active branch'];
        }

        if ($active->count() === 1) {
            return ['branch' => ['id' => (int) $active->first()->id, 'name' => (string) $active->first()->name], 'reason' => null];
        }

        $named = $active->filter(fn (object $branch): bool => in_array(
            mb_strtolower((string) preg_replace('/\s+/u', '', (string) $branch->name)),
            self::MAIN_NAMES,
            true,
        ))->values();

        return match ($named->count()) {
            1 => ['branch' => ['id' => (int) $named->first()->id, 'name' => (string) $named->first()->name], 'reason' => null],
            0 => ['branch' => null, 'reason' => "{$active->count()} active branches and none is named as the main branch"],
            default => ['branch' => null, 'reason' => "{$named->count()} active branches are named as the main branch"],
        };
    }

    private function candidates(): Builder
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
                            ->where('permissions.name', self::VIEW_ALL)
                            ->where('permissions.guard_name', 'web')))))
            ->whereNotExists(fn (Builder $direct) => $direct->select(DB::raw(1))
                ->from('model_has_permissions')
                ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
                ->whereColumn('model_has_permissions.model_id', 'users.id')
                ->where('model_has_permissions.model_type', self::USER_TYPE)
                ->where('permissions.name', self::VIEW_ALL)
                ->where('permissions.guard_name', 'web'))
            ->orderBy('users.id');
    }

    /**
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
};
