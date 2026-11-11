<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Give every money account that has no branch the main branch, so each branch
 * reconciles its own accounts (owner answer 9, 2026-11-11).
 *
 * A money account is one carrying a `cash_kind`. Accounts that hold no money
 * belong to no branch and are never touched, and neither is a money account
 * that already has a branch.
 *
 * The main branch is:
 * - the only active branch, when there is just one;
 * - else the one active branch the table marks as the main branch or head
 *   office ({@see self::isMarkedMain()}): coded MAIN, HO or HQ, or named Main,
 *   Main Branch, Main Office, Head Office or Headquarters. This is the same
 *   rule as the staff data fix (O2, DefaultBranchAssignment::mainBranch());
 *   keep the two in step;
 * - else nobody can say which branch is meant: nothing is written, and every
 *   account is listed as left with no branch. Such an account stays visible to
 *   `branches:view_all` only, as every money account was before.
 *
 * Each change writes one audit row as a system change (no user, no address),
 * the `updated` row the Auditable trait would write for the same edit. The
 * update goes through the query builder, guarded on the branch still being
 * empty, so the trait writes no second row, an account given a branch while
 * the fix ran is left as it is, and a second run changes nothing.
 *
 * Used by `accounting:assign-cash-account-branches` (with `--dry-run` to
 * preview a database). The 2026_11_11_400100 migration does the same on
 * deploy, written out with the query builder alone so that it depends on no
 * application class; keep them in step (AssignCashAccountBranchesTest compares
 * them).
 */
class CashAccountBranchAssignment
{
    /** Branch codes that mark the main branch or head office, compared in upper case. */
    public const MAIN_CODES = ['MAIN', 'HO', 'HQ'];

    /** Branch names that mark it, compared in lower case with spaces collapsed. */
    public const MAIN_NAMES = ['main', 'main branch', 'main office', 'head office', 'headquarters'];

    /** Polymorphic rows store the full class name; there is no morph map. */
    private const AUDITABLE_TYPE = 'App\Models\AccountingAccount';

    /**
     * Assign every money account with no branch to the main branch, or with
     * `$dryRun` only say which.
     *
     * @return array{branch: array{id: int, name: string}|null, assigned: list<array{id: int, code: string, name: string}>, skipped: list<array{id: int, code: string, name: string, reason: string}>}
     */
    public function run(bool $dryRun): array
    {
        $branch = self::mainBranch();
        $result = ['branch' => $branch, 'assigned' => [], 'skipped' => []];

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

            if ($dryRun || $this->write($account, $branch)) {
                $result['assigned'][] = $entry;

                continue;
            }

            $result['skipped'][] = [...$entry, 'reason' => 'it was given a branch while the fix ran'];
        }

        if ($result['skipped'] !== []) {
            Log::warning('Money accounts left with no branch; only branches:view_all can reconcile them.', ['accounts' => $result['skipped']]);
        }

        return $result;
    }

    /**
     * The main branch, or null when it can't be decided.
     *
     * @return array{id: int, name: string}|null
     */
    public static function mainBranch(): ?array
    {
        $active = DB::table('branches')->where('is_active', true)->orderBy('id')->get(['id', 'name', 'code']);

        $candidates = $active->count() === 1
            ? $active
            : $active->filter(static fn (object $branch): bool => self::isMarkedMain((string) $branch->code, (string) $branch->name));

        if ($candidates->count() !== 1) {
            return null;
        }

        $branch = $candidates->first();

        return ['id' => (int) $branch->id, 'name' => (string) $branch->name];
    }

    /** Whether a branch's code or name marks it as the main branch or head office. */
    public static function isMarkedMain(string $code, string $name): bool
    {
        $name = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));

        return in_array(mb_strtoupper(trim($code)), self::MAIN_CODES, true)
            || in_array($name, self::MAIN_NAMES, true);
    }

    /**
     * The one line the migration prints to the deploy log.
     *
     * @param  array{branch: array{id: int, name: string}|null, assigned: list<mixed>, skipped: list<mixed>}  $result
     */
    public static function summary(array $result): string
    {
        return sprintf(
            'money account branches: %d assigned%s, %d left with no branch',
            count($result['assigned']),
            $result['branch'] === null ? '' : ' to '.$result['branch']['name'],
            count($result['skipped']),
        );
    }

    /**
     * Assign one account and record it, guarded on its branch still being
     * empty.
     *
     * @param  array{id: int, name: string}  $branch
     */
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
}
