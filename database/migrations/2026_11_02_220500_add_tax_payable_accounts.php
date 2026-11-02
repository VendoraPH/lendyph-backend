<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * Spelled out rather than read off ReleaseDeductionAccountsBackfill and the
     * accounting models: this migration has to write on its last deployment
     * exactly what it wrote on its first, whatever those classes become.
     * Polymorphic rows store the full class name; there is no morph map.
     */
    private const AUDIT_ACCOUNT_ADDED = 'release_deduction_account_added';

    private const AUDIT_ROLE_MAPPED = 'release_deduction_role_mapped';

    private const ACCOUNT_TYPE = 'App\Models\AccountingAccount';

    private const MAPPING_TYPE = 'App\Models\AccountingAccountMapping';

    private const NEW_ACCOUNTS = [
        'documentary_stamp_tax_payable' => ['code' => '2230', 'name' => 'Documentary Stamp Tax Payable', 'type' => 'liability', 'parent' => '2200', 'cash_flow_category' => 'financing'],
    ];

    /** role => [code, name] of the existing account it points at. */
    private const EXISTING = [
        'withholding_tax_payable' => ['2210', 'Withholding Tax Payable'],
        'other_taxes_payable' => ['2220', 'Other Taxes Payable'],
    ];

    private const DEDUCTIONS = [
        'documentary_stamp_tax_payable' => 'documentary stamp taxes',
        'withholding_tax_payable' => 'withholding taxes',
        'other_taxes_payable' => 'other taxes',
    ];

    private const DECIDED = 'as approved on 2026-11-02';

    /**
     * Add the tax payable roles (decision 4b, 2026-11-02) to a chart seeded
     * before them: 2230 Documentary Stamp Tax Payable, a new liability under
     * 2200 Taxes Payable, for
     * `documentary_stamp_tax_payable`; the existing 2210 Withholding Tax
     * Payable for `withholding_tax_payable`; and the existing 2220 Other Taxes
     * Payable for `other_taxes_payable`.
     *
     * The same accounts, roles, skips and audit rows as
     * ReleaseDeductionAccountsBackfill gives these two roles (its docblock
     * explains them), written here with the query builder alone so the
     * migration depends on no application class.
     * ReleaseDeductionAccountsBackfillTest holds the two to the same outcome.
     * `php artisan accounting:backfill-release-deduction-accounts --dry-run`
     * shows what this will change on a database before it is deployed there.
     *
     * No chart, nothing written. A role already mapped is left alone, so
     * running it again changes nothing. Each account added and each role
     * mapped gets one audit row as a system change (no user); anything it
     * cannot be sure of is skipped and listed with the reason. A skipped role
     * never stops a release: the tax is booked where it went before and a
     * warning is logged. Posted journals are never touched.
     *
     * The summary line is printed as well as logged, so the count shows in the
     * deploy log. Not under the test suite, which runs every migration on a
     * fresh database for each worker and would print it each time.
     */
    public function up(): void
    {
        $result = ['chart' => false, 'created' => [], 'mapped' => [], 'kept' => [], 'skipped' => []];

        if (DB::table('accounting_accounts')->exists()) {
            $result['chart'] = true;

            DB::transaction(function () use (&$result): void {
                foreach (array_keys(self::DEDUCTIONS) as $role) {
                    $this->handle($role, $result);
                }
            });
        }

        $summary = $result['chart']
            ? sprintf(
                'tax payable accounts: %d account(s) added, %d role(s) mapped, %d already mapped, %d skipped',
                count($result['created']),
                count($result['mapped']),
                count($result['kept']),
                count($result['skipped']),
            )
            : 'tax payable accounts: no chart of accounts, nothing to add';

        Log::info($summary, $result);

        if ($result['skipped'] !== []) {
            Log::warning('Tax payable roles left unmapped; set them in Accounting → Settings → Default Accounts.', [
                'rows' => $result['skipped'],
            ]);
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    /**
     * Nothing to undo. Removing the account or the roles again would send new
     * releases' taxes back to income, and an account that has been posted to
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

        [$account, $reason] = isset(self::EXISTING[$role])
            ? $this->existing(self::EXISTING[$role][0], self::EXISTING[$role][1], 'liability')
            : $this->byCode(self::NEW_ACCOUNTS[$role]);

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

    /**
     * An existing account by code that must already be right: active,
     * postable, of the role's type, and still carrying its name. A 2210 or
     * 2220 an administrator renamed for something else is not taken on its code alone.
     *
     * @return array{0: object|null, 1: string|null}
     */
    private function existing(string $code, string $name, string $type): array
    {
        $account = DB::table('accounting_accounts')->where('code', $code)->first();

        if ($account === null) {
            return [null, "{$code} does not exist in this chart"];
        }

        if (! $this->postable($account, $type) || $this->normalize($account->name) !== $this->normalize($name)) {
            return [null, "{$code} is {$account->name}, not an active, postable {$type} account named {$name}"];
        }

        return [$account, null];
    }

    /**
     * The account a new-account role points at, found by its NAME anywhere in
     * the chart before its code is looked at, so a chart that already keeps
     * "Documentary Stamp Tax Payable" under another code never gets a second
     * one:
     *
     * - one account with that name, active, postable and of the role's type:
     *   that account, whatever its code;
     * - more than one, or one that cannot be posted to: not certain, skipped;
     * - none, and the code free: none yet, to be created;
     * - none, and the code used by another account: skipped.
     *
     * @param  array{code: string, name: string, type: string}  $spec
     * @return array{0: object|null, 1: string|null}
     */
    private function byCode(array $spec): array
    {
        $namesakes = DB::table('accounting_accounts')->orderBy('code')->get()
            ->filter(fn (object $account): bool => $this->normalize($account->name) === $this->normalize($spec['name']))
            ->values();

        if ($namesakes->count() > 1) {
            return [null, "more than one account is named {$spec['name']} (".$namesakes->pluck('code')->implode(', ').')'];
        }

        if ($namesakes->count() === 1) {
            $account = $namesakes->first();

            return $this->postable($account, $spec['type'])
                ? [$account, null]
                : [null, "{$account->code} {$account->name} is not an active, postable {$spec['type']} account"];
        }

        $taken = DB::table('accounting_accounts')->where('code', $spec['code'])->first();

        if ($taken !== null) {
            return [null, "{$spec['code']} is already used by {$taken->name}"];
        }

        return [null, null];
    }

    private function postable(object $account, string $type): bool
    {
        return $account->type === $type
            && (bool) $account->is_active
            && ! (bool) $account->is_group
            && ! (bool) $account->is_contra
            && $account->cash_kind === null;
    }

    private function normalize(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($name)) ?? '');
    }

    /**
     * @param  array{code: string, name: string, type: string, parent: string, cash_flow_category: string}  $spec
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
            'normal_balance' => 'credit',
            'is_contra' => false,
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
            'new_values' => json_encode(['code' => $spec['code'], 'name' => $spec['name'], 'type' => $spec['type'], 'parent_code' => $parent === null ? null : $spec['parent']]),
            'ip_address' => null,
            'user_agent' => null,
            'description' => "System change: added account {$spec['code']} {$spec['name']} ({$spec['type']}) for ".self::DEDUCTIONS[$role].' withheld at release, '.self::DECIDED.'.',
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
            'description' => 'System change: '.ucfirst(self::DEDUCTIONS[$role])." withheld at release now post to {$account->code} {$account->name} (role {$role}), ".self::DECIDED.'.',
            'created_at' => $now,
        ]);
    }
};
