<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * Spelled out rather than read off AccountingRolesBackfill and the
     * accounting models: this migration has to write on its last deployment
     * exactly what it wrote on its first, whatever those classes become.
     * Polymorphic rows store the full class name; there is no morph map.
     */
    private const AUDIT_ACCOUNT_ADDED = 'posting_role_account_added';

    private const AUDIT_ROLE_MAPPED = 'posting_role_mapped';

    private const ACCOUNT_TYPE = 'App\Models\AccountingAccount';

    private const MAPPING_TYPE = 'App\Models\AccountingAccountMapping';

    private const NEW_ACCOUNTS = [
        'gcash_service_income' => ['code' => '4090', 'name' => 'GCash Service Income', 'type' => 'income', 'parent' => '4000', 'is_contra' => false, 'normal_balance' => 'credit', 'cash_flow_category' => 'operating'],
        'loan_adjustments' => ['code' => '1180', 'name' => 'Loan Adjustments Clearing', 'type' => 'asset', 'parent' => '1000', 'is_contra' => false, 'normal_balance' => 'debit', 'cash_flow_category' => 'investing'],
        'unearned_restructure_income' => ['code' => '1210', 'name' => 'Unearned Interest and Penalty – Restructured Loans', 'type' => 'asset', 'parent' => '1000', 'is_contra' => true, 'normal_balance' => 'credit', 'cash_flow_category' => 'investing'],
        'suspense' => ['code' => '2390', 'name' => 'Suspense Account', 'type' => 'liability', 'parent' => '2000', 'is_contra' => false, 'normal_balance' => 'credit', 'cash_flow_category' => 'financing'],
    ];

    /** [code, name, type] of the existing account `net_surplus` points at. */
    private const NET_SURPLUS = ['3030', 'Retained Earnings', 'equity'];

    private const ROLES = ['gcash_service_income', 'loan_adjustments', 'unearned_restructure_income', 'net_surplus', 'suspense'];

    private const PURPOSES = [
        'gcash_service_income' => 'GCash service charges',
        'loan_adjustments' => 'balance adjustments',
        'unearned_restructure_income' => 'interest and penalty capitalised by restructures',
        'net_surplus' => 'the year-end close',
        'suspense' => 'receipts not yet identified',
    ];

    private const DECIDED = 'as approved in the Phase 2 plan on 2026-11-08';

    /**
     * Add the Phase 2 posting roles to a chart seeded before them: 4090
     * GCash Service Income, 1180 Loan Adjustments Clearing, 1210 Unearned
     * Interest and Penalty – Restructured Loans (a contra asset under 1000)
     * and 2390 Suspense Account, new, and the existing 3030 Retained
     * Earnings for `net_surplus`.
     *
     * The same accounts, roles, skips and audit rows as
     * AccountingRolesBackfill (its docblock explains them), written here with
     * the query builder alone so the migration depends on no application
     * class. PostingRolesBackfillTest holds the two to the same outcome.
     * `php artisan accounting:backfill-posting-roles --dry-run` shows what
     * this will change on a database before it is deployed there.
     *
     * No chart, nothing written. A role already mapped is left alone, so
     * running it again changes nothing. Each account added and each role
     * mapped gets one audit row as a system change (no user); anything it
     * cannot be sure of is skipped and listed with the reason, and the
     * postings that need that role are refused until someone sets it in
     * Accounting → Settings → Default Accounts. Posted journals are never
     * touched.
     *
     * The summary line is printed as well as logged, so the count shows in
     * the deploy log. Not under the test suite, which runs every migration on
     * a fresh database for each worker and would print it each time.
     */
    public function up(): void
    {
        $result = ['chart' => false, 'created' => [], 'mapped' => [], 'kept' => [], 'skipped' => []];

        if (DB::table('accounting_accounts')->exists()) {
            $result['chart'] = true;

            DB::transaction(function () use (&$result): void {
                foreach (self::ROLES as $role) {
                    $this->handle($role, $result);
                }
            });
        }

        $summary = $result['chart']
            ? sprintf(
                'posting roles: %d account(s) added, %d role(s) mapped, %d already mapped, %d skipped',
                count($result['created']),
                count($result['mapped']),
                count($result['kept']),
                count($result['skipped']),
            )
            : 'posting roles: no chart of accounts, nothing to add';

        Log::info($summary, $result);

        if ($result['skipped'] !== []) {
            Log::warning('Posting roles left unmapped; set them in Accounting → Settings → Default Accounts.', [
                'rows' => $result['skipped'],
            ]);
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    /**
     * Nothing to undo. Removing the accounts or the roles again would refuse
     * the postings that use them, and an account that has been posted to
     * cannot be removed. Each change is on record in the audit log.
     */
    public function down(): void {}

    /**
     * @param  array{chart: bool, created: list<mixed>, mapped: list<mixed>, kept: list<mixed>, skipped: list<mixed>}  $result
     */
    private function handle(string $role, array &$result): void
    {
        $mapped = DB::table('accounting_account_mappings')->where('role', $role)->first();

        if ($mapped !== null) {
            $result['kept'][] = [
                'role' => $role,
                'code' => DB::table('accounting_accounts')->where('id', $mapped->accounting_account_id)->value('code'),
            ];

            return;
        }

        [$account, $reason] = $role === 'net_surplus'
            ? $this->existing(...self::NET_SURPLUS)
            : $this->byName(self::NEW_ACCOUNTS[$role]);

        if ($reason !== null) {
            $result['skipped'][] = ['role' => $role, 'reason' => $reason];

            return;
        }

        if ($account === null) {
            $spec = self::NEW_ACCOUNTS[$role];
            $result['created'][] = ['role' => $role, 'code' => $spec['code'], 'name' => $spec['name'], 'type' => $spec['type']];
            $account = $this->create($role, $spec);
        }

        $result['mapped'][] = ['role' => $role, 'code' => $account->code, 'name' => $account->name];

        $this->map($role, $account);
    }

    /** @return array{0: object|null, 1: string|null} */
    private function existing(string $code, string $name, string $type): array
    {
        $account = DB::table('accounting_accounts')->where('code', $code)->first();

        if ($account === null) {
            return [null, "{$code} does not exist in this chart"];
        }

        if (! $this->serves($account, $type, false) || $this->normalize($account->name) !== $this->normalize($name)) {
            return [null, "{$code} is {$account->name}, not an active, postable {$type} account named {$name}"];
        }

        return [$account, null];
    }

    /**
     * @param  array{code: string, name: string, type: string, is_contra: bool}  $spec
     * @return array{0: object|null, 1: string|null}
     */
    private function byName(array $spec): array
    {
        $namesakes = DB::table('accounting_accounts')->orderBy('code')->get()
            ->filter(fn (object $account): bool => $this->normalize($account->name) === $this->normalize($spec['name']))
            ->values();

        if ($namesakes->count() > 1) {
            return [null, "more than one account is named {$spec['name']} (".$namesakes->pluck('code')->implode(', ').')'];
        }

        if ($namesakes->count() === 1) {
            $account = $namesakes->first();
            $kind = $spec['is_contra'] ? "contra {$spec['type']}" : $spec['type'];

            return $this->serves($account, $spec['type'], $spec['is_contra'])
                ? [$account, null]
                : [null, "{$account->code} {$account->name} is not an active, postable {$kind} account"];
        }

        $taken = DB::table('accounting_accounts')->where('code', $spec['code'])->first();

        if ($taken !== null) {
            return [null, "{$spec['code']} is already used by {$taken->name}"];
        }

        return [null, null];
    }

    private function serves(object $account, string $type, bool $contra): bool
    {
        return $account->type === $type
            && (bool) $account->is_active
            && ! (bool) $account->is_group
            && (bool) $account->is_contra === $contra
            && $account->cash_kind === null;
    }

    private function normalize(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($name)) ?? '');
    }

    /**
     * @param  array{code: string, name: string, type: string, parent: string, is_contra: bool, normal_balance: string, cash_flow_category: string}  $spec
     */
    private function create(string $role, array $spec): object
    {
        $now = now();
        $parent = DB::table('accounting_accounts')
            ->where('code', $spec['parent'])
            ->where('type', $spec['type'])
            ->where('is_group', true)
            ->value('id');

        $id = DB::table('accounting_accounts')->insertGetId([
            'code' => $spec['code'],
            'name' => $spec['name'],
            'type' => $spec['type'],
            'normal_balance' => $spec['normal_balance'],
            'is_contra' => $spec['is_contra'],
            'parent_id' => $parent,
            'is_group' => false,
            'is_active' => true,
            'cash_kind' => null,
            'cash_flow_category' => $spec['cash_flow_category'],
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('audit_logs')->insert([
            'user_id' => null,
            'action' => self::AUDIT_ACCOUNT_ADDED,
            'auditable_type' => self::ACCOUNT_TYPE,
            'auditable_id' => $id,
            'old_values' => null,
            'new_values' => json_encode(['code' => $spec['code'], 'name' => $spec['name'], 'type' => $spec['type'], 'is_contra' => $spec['is_contra'], 'parent_code' => $parent === null ? null : $spec['parent']]),
            'ip_address' => null,
            'user_agent' => null,
            'description' => "System change: added account {$spec['code']} {$spec['name']} ({$spec['type']}) for ".self::PURPOSES[$role].', '.self::DECIDED.'.',
            'created_at' => $now,
        ]);

        return (object) ['id' => $id, 'code' => $spec['code'], 'name' => $spec['name']];
    }

    private function map(string $role, object $account): void
    {
        $now = now();

        $id = DB::table('accounting_account_mappings')->insertGetId([
            'role' => $role,
            'accounting_account_id' => $account->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('audit_logs')->insert([
            'user_id' => null,
            'action' => self::AUDIT_ROLE_MAPPED,
            'auditable_type' => self::MAPPING_TYPE,
            'auditable_id' => $id,
            'old_values' => null,
            'new_values' => json_encode(['role' => $role, 'account_code' => $account->code, 'account_name' => $account->name]),
            'ip_address' => null,
            'user_agent' => null,
            'description' => "System change: role {$role} (".self::PURPOSES[$role].") now posts to {$account->code} {$account->name}, ".self::DECIDED.'.',
            'created_at' => $now,
        ]);
    }
};
