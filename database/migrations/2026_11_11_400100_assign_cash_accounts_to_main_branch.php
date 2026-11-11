<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * Spelled out rather than read off CashAccountBranchAssignment: this
     * migration has to write on its last deployment exactly what it wrote on
     * its first, whatever that class becomes. Polymorphic rows store the full
     * class name; there is no morph map.
     */
    private const MAIN_CODES = ['MAIN', 'HO', 'HQ'];

    private const MAIN_NAMES = ['main', 'main branch', 'main office', 'head office', 'headquarters'];

    private const AUDITABLE_TYPE = 'App\Models\AccountingAccount';

    /**
     * Give every money account with no branch the main branch (owner answer
     * 9, 2026-11-11: each branch reconciles its own accounts).
     *
     * The same selection, skips and audit rows as CashAccountBranchAssignment,
     * whose docblock explains them, written here with the query builder alone.
     * AssignCashAccountBranchesTest holds the two to the same outcome.
     * `php artisan accounting:assign-cash-account-branches --dry-run` shows
     * what this will change on a database before it is deployed there.
     *
     * Only money accounts still with no branch are written, guarded on that,
     * so a second run changes nothing. When the main branch can't be decided,
     * nothing is written and the accounts are logged; they stay visible to
     * `branches:view_all` only, as before. Each change writes one audit row as
     * a system change (no user). On a fresh database there is no chart yet, so
     * this does nothing.
     *
     * The summary line is printed as well as logged, so the count shows in the
     * deploy log. Not under the test suite, which runs every migration on a
     * fresh database for each worker and would print it each time.
     */
    public function up(): void
    {
        $branch = $this->mainBranch();
        $result = ['assigned' => [], 'skipped' => []];

        $accounts = DB::table('accounting_accounts')
            ->whereNotNull('cash_kind')
            ->whereNull('branch_id')
            ->orderBy('code')
            ->orderBy('id')
            ->get(['id', 'code', 'name']);

        foreach ($accounts as $account) {
            $entry = ['id' => (int) $account->id, 'code' => (string) $account->code, 'name' => (string) $account->name];

            if ($branch === null) {
                $result['skipped'][] = [...$entry, 'reason' => 'no main branch could be decided'];

                continue;
            }

            if ($this->write($account, $branch)) {
                $result['assigned'][] = $entry;

                continue;
            }

            $result['skipped'][] = [...$entry, 'reason' => 'it was given a branch while the fix ran'];
        }

        $summary = sprintf(
            'money account branches: %d assigned%s, %d left with no branch',
            count($result['assigned']),
            $branch === null ? '' : ' to '.$branch['name'],
            count($result['skipped']),
        );

        Log::info($summary, $result);

        if ($result['skipped'] !== []) {
            Log::warning('Money accounts left with no branch; only branches:view_all can reconcile them.', ['accounts' => $result['skipped']]);
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    /**
     * Nothing to undo here: the column's own migration drops it, and audit
     * rows are never deleted.
     */
    public function down(): void {}

    /** @return array{id: int, name: string}|null */
    private function mainBranch(): ?array
    {
        $active = DB::table('branches')->where('is_active', true)->orderBy('id')->get(['id', 'name', 'code']);

        $candidates = $active->count() === 1
            ? $active
            : $active->filter(function (object $branch): bool {
                $name = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $branch->name)));

                return in_array(mb_strtoupper(trim((string) $branch->code)), self::MAIN_CODES, true)
                    || in_array($name, self::MAIN_NAMES, true);
            });

        if ($candidates->count() !== 1) {
            return null;
        }

        $branch = $candidates->first();

        return ['id' => (int) $branch->id, 'name' => (string) $branch->name];
    }

    /** @param  array{id: int, name: string}  $branch */
    private function write(object $account, array $branch): bool
    {
        return DB::transaction(function () use ($account, $branch): bool {
            $now = now();

            $updated = DB::table('accounting_accounts')
                ->where('id', $account->id)
                ->whereNull('branch_id')
                ->update(['branch_id' => $branch['id'], 'updated_at' => $now]);

            if ($updated === 0) {
                return false;
            }

            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'updated',
                'auditable_type' => self::AUDITABLE_TYPE,
                'auditable_id' => $account->id,
                'old_values' => json_encode(['branch_id' => null]),
                'new_values' => json_encode(['branch_id' => $branch['id']]),
                'ip_address' => null,
                'user_agent' => null,
                'description' => sprintf(
                    'System correction: Account %s %s now belongs to %s, so that branch reconciles it.',
                    $account->code,
                    $account->name,
                    $branch['name'],
                ),
                'created_at' => $now,
            ]);

            return true;
        });
    }
};
