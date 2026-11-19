<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /** Lower case with no spaces; a code alone never marks the main branch. */
    private const MAIN_NAMES = ['main', 'mainbranch', 'mainoffice', 'headoffice', 'headquarters'];

    /** Polymorphic rows store the full class name; there is no morph map. */
    private const BRANCH_TYPE = 'App\Models\Branch';

    /**
     * Data fix 1 (Phase 6 plan §5, multi-branch decision 4): set the
     * main-office flag on the one branch today's main-branch rule finds.
     *
     * The same rule, guard, audit row and outcome as MainOfficeMarker, whose
     * docblock explains them, spelled out with the query builder alone so this
     * migration writes on its last deployment what it wrote on its first.
     * MarkMainOfficeTest holds the two to the same outcome.
     * `php artisan branches:mark-main-office --dry-run` previews a database.
     *
     * Nothing is written when a branch is already flagged, or when the rule
     * finds none or several; those are logged with the active branches, for
     * staff to pick in Settings > Branches. On a fresh database there are no
     * branches yet, so this does nothing.
     */
    public function up(): void
    {
        $outcome = DB::transaction(function (): array {
            $flagged = DB::table('branches')->where('is_main_office', true)->lockForUpdate()->first(['id', 'name']);

            if ($flagged !== null) {
                return ['marked' => null, 'reason' => "{$flagged->name} is already the main office", 'branches' => []];
            }

            $active = DB::table('branches')->where('status', 'active')->orderBy('id')->lockForUpdate()->get(['id', 'name', 'code']);
            [$branch, $basis, $reason] = $this->ruleFinds($active);

            if ($branch === null) {
                return ['marked' => null, 'reason' => $reason, 'branches' => $active->map(fn (object $row) => "{$row->name} ({$row->code}, id {$row->id})")->all()];
            }

            $now = now();
            $updated = DB::table('branches')
                ->where('id', $branch->id)
                ->where('status', 'active')
                ->where('is_main_office', false)
                ->update(['is_main_office' => true, 'updated_at' => $now]);

            if ($updated === 0) {
                return ['marked' => null, 'reason' => "{$branch->name} changed while the fix ran", 'branches' => []];
            }

            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'updated',
                'auditable_type' => self::BRANCH_TYPE,
                'auditable_id' => $branch->id,
                'old_values' => json_encode(['is_main_office' => false]),
                'new_values' => json_encode(['is_main_office' => true]),
                'ip_address' => null,
                'user_agent' => null,
                'description' => sprintf(
                    'System correction: Branch %s (%s) marked as the main office (%s).',
                    json_encode((string) $branch->name, JSON_UNESCAPED_UNICODE),
                    $branch->code,
                    $basis,
                ),
                'created_at' => $now,
            ]);

            return ['marked' => $branch->name, 'reason' => null, 'branches' => []];
        });

        $summary = $outcome['marked'] !== null
            ? "main office: marked {$outcome['marked']}"
            : "main office: nothing marked ({$outcome['reason']})";

        Log::info($summary, $outcome);

        if ($outcome['branches'] !== []) {
            Log::warning('No branch was marked as the main office; pick it in Settings > Branches.', $outcome);
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    /**
     * Nothing to undo: the main office can be moved in Settings > Branches,
     * and audit rows are never deleted.
     */
    public function down(): void {}

    /**
     * @param  Collection<int, object>  $active
     * @return array{0: object|null, 1: string|null, 2: string|null}
     */
    private function ruleFinds(Collection $active): array
    {
        if ($active->isEmpty()) {
            return [null, null, 'there is no active branch'];
        }

        if ($active->count() === 1) {
            return [$active->first(), 'the only active branch', null];
        }

        $named = $active->filter(fn (object $row): bool => in_array(
            mb_strtolower((string) preg_replace('/\s+/u', '', (string) $row->name)),
            self::MAIN_NAMES,
            true,
        ))->values();

        return match ($named->count()) {
            1 => [$named->first(), 'the branch named as the main branch', null],
            0 => [null, null, "{$active->count()} active branches and none is named as the main branch"],
            default => [null, null, "{$named->count()} active branches are named as the main branch"],
        };
    }
};
