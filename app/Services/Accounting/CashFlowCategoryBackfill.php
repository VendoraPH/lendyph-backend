<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use Illuminate\Support\Facades\DB;

/**
 * Move an existing chart's loan accounts to the `operating` cash flow
 * category (Phase 2, decision 9: loans are operating activities).
 *
 * The accounts are the ones mapped to `loans_receivable`,
 * `interest_receivable`, `penalty_receivable`, `allowance_credit_losses`,
 * `loan_adjustments` and `unearned_restructure_income`, plus every account
 * {@see LoanReceivableAccounts} names. New charts are seeded that way
 * (ChartOfAccountsSeeder::OPERATING_LOAN_ACCOUNT_CODES); a chart seeded
 * before reads `investing`, the old default for an asset.
 *
 * Writes only what it is sure of:
 * - an account still `investing` that no user ever reclassified moves, with
 *   one audit row as a system change (no user);
 * - one already `operating` is kept, so a second run changes nothing;
 * - one reading anything else, or whose `cash_flow_category` a user changed
 *   at any time (an audit row with a user that changed it), is skipped with
 *   the reason: that was somebody's choice.
 * No chart, nothing written. Posted journals are never touched; the cash flow
 * statement reads the category when it is run.
 *
 * The 2026_11_09_110000 migration does the same on deploy, written out with
 * the query builder so it depends on no application class; keep the two in
 * step (CashFlowCategoryBackfillTest compares them).
 * `php artisan accounting:cash-flow-loans-operating --dry-run` shows what it
 * will change on a database first.
 */
class CashFlowCategoryBackfill
{
    public const AUDIT_ACTION = 'cash_flow_category_reclassified';

    public const ROLES = [
        'loans_receivable',
        'interest_receivable',
        'penalty_receivable',
        'allowance_credit_losses',
        'loan_adjustments',
        'unearned_restructure_income',
    ];

    private const DECIDED = 'loans are operating activities, as approved in the Phase 2 plan on 2026-11-08';

    /**
     * @return array{
     *     chart: bool,
     *     moved: list<array{code: string, name: string}>,
     *     kept: list<array{code: string, name: string}>,
     *     skipped: list<array{code: string, name: string, reason: string}>,
     * }
     */
    public function run(bool $dryRun): array
    {
        $result = ['chart' => false, 'moved' => [], 'kept' => [], 'skipped' => []];

        if (! DB::table('accounting_accounts')->exists()) {
            return $result;
        }

        $result['chart'] = true;

        $work = function () use ($dryRun, &$result): void {
            foreach ($this->accounts() as $account) {
                $this->handle($account, $dryRun, $result);
            }
        };

        $dryRun ? $work() : DB::transaction($work);

        return $result;
    }

    /**
     * @param  array{chart: bool, moved: list<mixed>, kept: list<mixed>, skipped: list<mixed>}  $result
     */
    public static function summary(array $result): string
    {
        if (! $result['chart']) {
            return 'cash flow categories: no chart of accounts, nothing to move';
        }

        return sprintf(
            'cash flow categories: %d loan account(s) moved to operating, %d already operating, %d skipped',
            count($result['moved']),
            count($result['kept']),
            count($result['skipped']),
        );
    }

    /**
     * The loan accounts, in code order, each once.
     *
     * @return list<object>
     */
    private function accounts(): array
    {
        $ids = DB::table('accounting_account_mappings')
            ->whereIn('role', self::ROLES)
            ->pluck('accounting_account_id')
            ->map(static fn ($id): int => (int) $id)
            ->merge(LoanReceivableAccounts::ids())
            ->unique()
            ->values()
            ->all();

        return DB::table('accounting_accounts')
            ->whereIn('id', $ids)
            ->where('is_group', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'cash_flow_category'])
            ->all();
    }

    /**
     * @param  array{chart: bool, moved: list<mixed>, kept: list<mixed>, skipped: list<mixed>}  $result
     */
    private function handle(object $account, bool $dryRun, array &$result): void
    {
        $named = ['code' => (string) $account->code, 'name' => (string) $account->name];

        if ($account->cash_flow_category === 'operating') {
            $result['kept'][] = $named;

            return;
        }

        if ($account->cash_flow_category !== 'investing') {
            $result['skipped'][] = $named + ['reason' => "it reads {$account->cash_flow_category}, not investing"];

            return;
        }

        if ($this->aUserChangedIt((int) $account->id)) {
            $result['skipped'][] = $named + ['reason' => 'a user set its cash flow category, so their choice is kept'];

            return;
        }

        $result['moved'][] = $named;

        if (! $dryRun) {
            $this->move($account);
        }
    }

    /** Whether any audit row with a user changed this account's category. */
    private function aUserChangedIt(int $accountId): bool
    {
        return DB::table('audit_logs')
            ->where('auditable_type', AccountingAccount::class)
            ->where('auditable_id', $accountId)
            ->whereNotNull('user_id')
            ->pluck('new_values')
            ->contains(static fn (?string $values): bool => is_array($decoded = json_decode((string) $values, true))
                && array_key_exists('cash_flow_category', $decoded));
    }

    private function move(object $account): void
    {
        $now = now();

        DB::table('accounting_accounts')->where('id', $account->id)->update([
            'cash_flow_category' => 'operating',
            'updated_at' => $now,
        ]);

        DB::table('audit_logs')->insert([
            'user_id' => null,
            'action' => self::AUDIT_ACTION,
            'auditable_type' => AccountingAccount::class,
            'auditable_id' => $account->id,
            'old_values' => json_encode(['cash_flow_category' => 'investing']),
            'new_values' => json_encode(['cash_flow_category' => 'operating']),
            'ip_address' => null,
            'user_agent' => null,
            'description' => "System change: {$account->code} {$account->name} now reads operating on the cash flow statement instead of investing, because ".self::DECIDED.'.',
            'created_at' => $now,
        ]);
    }
}
