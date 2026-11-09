<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use Illuminate\Support\Facades\DB;

/**
 * Give a chart of accounts seeded before Phase 2 the accounts and posting
 * roles its posting rules need (approved in the Phase 2 plan, 2026-11-08):
 *
 * - `gcash_service_income` → 4090 GCash Service Income, a new income account
 *   under 4000 (decision 6);
 * - `loan_adjustments` → 1180 Loan Adjustments Clearing, a new asset under
 *   1000 (decision 7);
 * - `unearned_restructure_income` → 1210 Unearned Interest and Penalty –
 *   Restructured Loans, a new CONTRA asset under 1000, beside 1200 and not
 *   under the 1100 heading (decision 2);
 * - `net_surplus` → the existing 3030 Retained Earnings (code and name), for
 *   the year-end close; the cooperative template brings its own later;
 * - `suspense` → 2390 Suspense Account, a new liability under 2000.
 *
 * ChartOfAccountsSeeder runs once and never over an existing chart, so a
 * deployment that already keeps books gets these from here. Without them the
 * postings that use them are refused (the engine fails closed on an unmapped
 * role); nothing else changes.
 *
 * A new account is looked for by its NAME anywhere in the chart first, so a
 * chart already carrying one under another code is mapped to it rather than
 * given a second. Every account must be active, postable (not a heading),
 * not a cash account, of the role's type, and a contra account exactly when
 * the role's is (only 1210). A new account goes under its heading when that
 * heading exists.
 *
 * Writes only what it is sure of, and skips with the reason: a role already
 * mapped (an administrator's choice is never changed, and a second run
 * changes nothing); a name carried by more than one account, or by one that
 * cannot serve the role; a code already taken by an account with another
 * name; 3030 missing, unusable or renamed. Each account added and each role
 * mapped gets one audit row as a system change (no user). Posted journals are
 * never touched.
 *
 * The 2026_11_08_100000 migration does the same on deploy, written out with
 * the query builder so it depends on no application class; keep the two in
 * step (PostingRolesBackfillTest compares them).
 * `php artisan accounting:backfill-posting-roles --dry-run` shows what it
 * will change on a database first.
 */
class AccountingRolesBackfill
{
    public const AUDIT_ACCOUNT_ADDED = 'posting_role_account_added';

    public const AUDIT_ROLE_MAPPED = 'posting_role_mapped';

    /**
     * The role each new account is for.
     *
     * @var array<string, array{code: string, name: string, type: string, parent: string, is_contra: bool, normal_balance: string, cash_flow_category: string}>
     */
    public const NEW_ACCOUNTS = [
        'gcash_service_income' => ['code' => '4090', 'name' => 'GCash Service Income', 'type' => 'income', 'parent' => '4000', 'is_contra' => false, 'normal_balance' => 'credit', 'cash_flow_category' => 'operating'],
        'loan_adjustments' => ['code' => '1180', 'name' => 'Loan Adjustments Clearing', 'type' => 'asset', 'parent' => '1000', 'is_contra' => false, 'normal_balance' => 'debit', 'cash_flow_category' => 'investing'],
        'unearned_restructure_income' => ['code' => '1210', 'name' => 'Unearned Interest and Penalty – Restructured Loans', 'type' => 'asset', 'parent' => '1000', 'is_contra' => true, 'normal_balance' => 'credit', 'cash_flow_category' => 'investing'],
        'suspense' => ['code' => '2390', 'name' => 'Suspense Account', 'type' => 'liability', 'parent' => '2000', 'is_contra' => false, 'normal_balance' => 'credit', 'cash_flow_category' => 'financing'],
    ];

    /** The existing account `net_surplus` points at: [code, name, type]. */
    public const NET_SURPLUS = ['3030', 'Retained Earnings', 'equity'];

    /** The roles, in the order they are handled. */
    public const ROLES = ['gcash_service_income', 'loan_adjustments', 'unearned_restructure_income', 'net_surplus', 'suspense'];

    private const PURPOSES = [
        'gcash_service_income' => 'GCash service charges',
        'loan_adjustments' => 'balance adjustments',
        'unearned_restructure_income' => 'interest and penalty capitalised by restructures',
        'net_surplus' => 'the year-end close',
        'suspense' => 'receipts not yet identified',
    ];

    private const DECIDED = 'as approved in the Phase 2 plan on 2026-11-08';

    /**
     * Add and map everything that can be, or with `$dryRun` only say what.
     *
     * @return array{
     *     chart: bool,
     *     created: list<array{role: string, code: string, name: string, type: string}>,
     *     mapped: list<array{role: string, code: string, name: string}>,
     *     kept: list<array{role: string, code: string|null}>,
     *     skipped: list<array{role: string, reason: string}>,
     * }
     */
    public function run(bool $dryRun): array
    {
        $result = ['chart' => false, 'created' => [], 'mapped' => [], 'kept' => [], 'skipped' => []];

        if (! DB::table('accounting_accounts')->exists()) {
            return $result;
        }

        $result['chart'] = true;

        $work = function () use ($dryRun, &$result): void {
            foreach (self::ROLES as $role) {
                $this->handle($role, $dryRun, $result);
            }
        };

        $dryRun ? $work() : DB::transaction($work);

        return $result;
    }

    /**
     * The one-line summary the command prints and the migration logs.
     *
     * @param  array{chart: bool, created: list<mixed>, mapped: list<mixed>, kept: list<mixed>, skipped: list<mixed>}  $result
     */
    public static function summary(array $result): string
    {
        if (! $result['chart']) {
            return 'posting roles: no chart of accounts, nothing to add';
        }

        return sprintf(
            'posting roles: %d account(s) added, %d role(s) mapped, %d already mapped, %d skipped',
            count($result['created']),
            count($result['mapped']),
            count($result['kept']),
            count($result['skipped']),
        );
    }

    /**
     * @param  array{chart: bool, created: list<mixed>, mapped: list<mixed>, kept: list<mixed>, skipped: list<mixed>}  $result
     */
    private function handle(string $role, bool $dryRun, array &$result): void
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
            $account = $dryRun ? (object) ['id' => null, 'code' => $spec['code'], 'name' => $spec['name']] : $this->create($role, $spec);
        }

        $result['mapped'][] = ['role' => $role, 'code' => $account->code, 'name' => $account->name];

        if (! $dryRun) {
            $this->map($role, $account);
        }
    }

    /**
     * An existing account by code that must already be right: active,
     * postable, of the role's type, not a contra or cash account, and still
     * carrying its name. A 3030 renamed for something else is not taken on
     * its code alone.
     *
     * @return array{0: object|null, 1: string|null}
     */
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
     * The account a new-account role points at, found by its name anywhere in
     * the chart before its code is looked at:
     *
     * - one account with that name that can serve the role: that account;
     * - more than one, or one that cannot serve it: not certain, skipped;
     * - none, and the code free: none yet, to be created;
     * - none, and the code used by another account: skipped.
     *
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

    /** Whether an account can serve a role of this type and contra flag. */
    private function serves(object $account, string $type, bool $contra): bool
    {
        return $account->type === $type
            && (bool) $account->is_active
            && ! (bool) $account->is_group
            && (bool) $account->is_contra === $contra
            && $account->cash_kind === null;
    }

    /** FeeOverlapDetector's normal form: lower case, runs of other characters as one space. */
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
            'auditable_type' => AccountingAccount::class,
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
            'auditable_type' => AccountingAccountMapping::class,
            'auditable_id' => $id,
            'old_values' => null,
            'new_values' => json_encode(['role' => $role, 'account_code' => $account->code, 'account_name' => $account->name]),
            'ip_address' => null,
            'user_agent' => null,
            'description' => "System change: role {$role} (".self::PURPOSES[$role].") now posts to {$account->code} {$account->name}, ".self::DECIDED.'.',
            'created_at' => $now,
        ]);
    }
}
