<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * Spelled out rather than read off CashFlowCategoryBackfill and the
     * accounting models: this migration has to write on its last deployment
     * exactly what it wrote on its first, whatever those classes become.
     * Polymorphic rows store the full class name; there is no morph map.
     */
    private const AUDIT_ACTION = 'cash_flow_category_reclassified';

    private const ACCOUNT_TYPE = 'App\Models\AccountingAccount';

    private const ROLES = [
        'loans_receivable',
        'interest_receivable',
        'penalty_receivable',
        'allowance_credit_losses',
        'loan_adjustments',
        'unearned_restructure_income',
    ];

    private const DECIDED = 'loans are operating activities, as approved in the Phase 2 plan on 2026-11-08';

    /**
     * Move an existing chart's loan accounts from `investing` to `operating`
     * on the cash flow statement (Phase 2, decision 9).
     *
     * The same accounts, skips and audit rows as CashFlowCategoryBackfill (its
     * docblock explains them), written here with the query builder alone.
     * CashFlowCategoryBackfillTest holds the two to the same outcome.
     * `php artisan accounting:cash-flow-loans-operating --dry-run` shows what
     * this will change on a database before it is deployed there.
     *
     * No chart, nothing written. Only an account still `investing` that no
     * user ever reclassified moves, with one system audit row (no user); a
     * second run changes nothing. Posted journals are never touched.
     */
    public function up(): void
    {
        $result = ['chart' => false, 'moved' => [], 'kept' => [], 'skipped' => []];

        if (DB::table('accounting_accounts')->exists()) {
            $result['chart'] = true;

            DB::transaction(function () use (&$result): void {
                foreach ($this->accounts() as $account) {
                    $this->handle($account, $result);
                }
            });
        }

        $summary = $result['chart']
            ? sprintf(
                'cash flow categories: %d loan account(s) moved to operating, %d already operating, %d skipped',
                count($result['moved']),
                count($result['kept']),
                count($result['skipped']),
            )
            : 'cash flow categories: no chart of accounts, nothing to move';

        Log::info($summary, $result);

        if ($result['skipped'] !== []) {
            Log::warning('Loan accounts left in their cash flow category; change them in Accounting → Chart of Accounts if needed.', [
                'rows' => $result['skipped'],
            ]);
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    /**
     * Nothing to undo. Moving the accounts back would undo a decided
     * classification; each change is on record in the audit log.
     */
    public function down(): void {}

    /** @return list<object> */
    private function accounts(): array
    {
        $ids = DB::table('accounting_account_mappings')
            ->whereIn('role', self::ROLES)
            ->pluck('accounting_account_id')
            ->map(static fn ($id): int => (int) $id)
            ->merge($this->receivableIds())
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
     * The account mapped to `loans_receivable`, or every postable account
     * under its heading (LoanReceivableAccounts::ids()).
     *
     * @return list<int>
     */
    private function receivableIds(): array
    {
        $mappedId = DB::table('accounting_account_mappings')->where('role', 'loans_receivable')->value('accounting_account_id');
        $mapped = $mappedId === null ? null : DB::table('accounting_accounts')->where('id', $mappedId)->first(['id', 'parent_id']);

        if ($mapped === null) {
            return [];
        }

        $headingId = $mapped->parent_id === null
            ? null
            : DB::table('accounting_accounts')->where('id', $mapped->parent_id)->where('is_group', true)->value('id');

        if ($headingId === null) {
            return [(int) $mapped->id];
        }

        return DB::table('accounting_accounts')
            ->where('parent_id', $headingId)
            ->where('is_group', false)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array{chart: bool, moved: list<mixed>, kept: list<mixed>, skipped: list<mixed>}  $result
     */
    private function handle(object $account, array &$result): void
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

        $setByAUser = DB::table('audit_logs')
            ->where('auditable_type', self::ACCOUNT_TYPE)
            ->where('auditable_id', $account->id)
            ->whereNotNull('user_id')
            ->pluck('new_values')
            ->contains(static fn (?string $values): bool => is_array($decoded = json_decode((string) $values, true))
                && array_key_exists('cash_flow_category', $decoded));

        if ($setByAUser) {
            $result['skipped'][] = $named + ['reason' => 'a user set its cash flow category, so their choice is kept'];

            return;
        }

        $result['moved'][] = $named;
        $now = now();

        DB::table('accounting_accounts')->where('id', $account->id)->update([
            'cash_flow_category' => 'operating',
            'updated_at' => $now,
        ]);

        DB::table('audit_logs')->insert([
            'user_id' => null,
            'action' => self::AUDIT_ACTION,
            'auditable_type' => self::ACCOUNT_TYPE,
            'auditable_id' => $account->id,
            'old_values' => json_encode(['cash_flow_category' => 'investing']),
            'new_values' => json_encode(['cash_flow_category' => 'operating']),
            'ip_address' => null,
            'user_agent' => null,
            'description' => "System change: {$account->code} {$account->name} now reads operating on the cash flow statement instead of investing, because ".self::DECIDED.'.',
            'created_at' => $now,
        ]);
    }
};
