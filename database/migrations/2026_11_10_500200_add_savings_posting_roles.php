<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * Spelled out rather than read off SavingsBooks and the accounting models:
     * this migration has to write on its last deployment exactly what it
     * wrote on its first, whatever those classes become. Polymorphic rows
     * store the full class name; there is no morph map.
     */
    private const AUDIT_ACCOUNT_ADDED = 'savings_books_account_added';

    private const AUDIT_ROLE_MAPPED = 'savings_books_role_mapped';

    private const ACCOUNT_TYPE = 'App\Models\AccountingAccount';

    private const MAPPING_TYPE = 'App\Models\AccountingAccountMapping';

    private const ROLES = ['savings_deposits', 'time_deposits', 'interest_expense_deposits', 'withholding_tax_deposits'];

    private const NEW_ACCOUNTS = [
        'savings_deposits' => ['code' => '2050', 'name' => 'Savings Deposits', 'type' => 'liability', 'parent' => '2000', 'normal_balance' => 'credit', 'cash_flow_category' => 'financing'],
        'time_deposits' => ['code' => '2060', 'name' => 'Time Deposits', 'type' => 'liability', 'parent' => '2000', 'normal_balance' => 'credit', 'cash_flow_category' => 'financing'],
        'interest_expense_deposits' => ['code' => '5160', 'name' => 'Interest Expense on Deposits', 'type' => 'expense', 'parent' => '5000', 'normal_balance' => 'debit', 'cash_flow_category' => 'operating'],
    ];

    private const WITHHOLDING_TAX = ['2210', 'Withholding Tax Payable', 'liability'];

    private const LABELS = [
        'savings_deposits' => 'Savings deposits',
        'time_deposits' => 'Time deposits',
        'interest_expense_deposits' => 'Interest expense on deposits',
        'withholding_tax_deposits' => 'Withholding tax on deposits',
    ];

    private const PURPOSES = [
        'savings_deposits' => 'members\' savings balances',
        'time_deposits' => 'members\' time deposits',
        'interest_expense_deposits' => 'interest paid on savings and time deposits',
        'withholding_tax_deposits' => 'tax withheld from deposit interest',
    ];

    private const DECIDED = 'as approved in the Phase 3 plan on 2026-11-10';

    /**
     * The savings posting roles on a chart that already carries 2050 Savings
     * Deposits (one seeded from the cooperative template before Phase 3):
     * add any missing 2060 Time Deposits and 5160 Interest Expense on
     * Deposits, and map `savings_deposits`, `time_deposits`,
     * `interest_expense_deposits` and `withholding_tax_deposits` (to the
     * account `withholding_tax_payable` uses, else 2210).
     *
     * A chart without 2050 (a lending company) is left alone: savings is
     * turned on there only by `php artisan savings:prepare-books`, which runs
     * the same rules (SavingsBooks) and has a --dry-run. No chart, nothing
     * written. A role already mapped is kept, so running this again changes
     * nothing. Each account added and each role mapped gets one audit row as
     * a system change (no user); anything uncertain is skipped with the
     * reason, and savings products that need that role can't be turned on
     * until someone sets it. Posted journals are never touched.
     */
    public function up(): void
    {
        $result = ['chart' => false, 'created' => [], 'mapped' => [], 'kept' => [], 'skipped' => []];

        if (DB::table('accounting_accounts')->where('code', '2050')->exists()) {
            $result['chart'] = true;

            DB::transaction(function () use (&$result): void {
                foreach (self::ROLES as $role) {
                    $this->handle($role, $result);
                }
            });
        }

        $summary = $result['chart']
            ? sprintf(
                'savings books: %d account(s) added, %d role(s) mapped, %d already mapped, %d skipped',
                count($result['created']),
                count($result['mapped']),
                count($result['kept']),
                count($result['skipped']),
            )
            : 'savings books: no chart with 2050 Savings Deposits, nothing to add';

        Log::info($summary, $result);

        if ($result['skipped'] !== []) {
            Log::warning('Savings posting roles left unmapped; set them in Accounting → Settings → Default Accounts.', [
                'rows' => $result['skipped'],
            ]);
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    /**
     * Nothing to undo. Removing the accounts or the roles would refuse every
     * savings posting, and an account that has been posted to cannot be
     * removed. Each change is on record in the audit log.
     */
    public function down(): void {}

    private function handle(string $role, array &$result): void
    {
        $mapped = DB::table('accounting_account_mappings')->where('role', $role)->first();

        if ($mapped !== null) {
            $result['kept'][] = ['role' => $role, 'code' => DB::table('accounting_accounts')->where('id', $mapped->accounting_account_id)->value('code')];

            return;
        }

        [$account, $reason] = $role === 'withholding_tax_deposits' ? $this->withholdingTaxAccount() : $this->byName(self::NEW_ACCOUNTS[$role]);

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

    private function withholdingTaxAccount(): array
    {
        $followed = DB::table('accounting_account_mappings')->where('role', 'withholding_tax_payable')->value('accounting_account_id');
        $account = $followed === null ? null : DB::table('accounting_accounts')->where('id', $followed)->first();

        if ($account !== null && $this->serves($account, 'liability')) {
            return [$account, null];
        }

        [$code, $name, $type] = self::WITHHOLDING_TAX;
        $account = DB::table('accounting_accounts')->where('code', $code)->first();

        if ($account === null) {
            return [null, "{$code} does not exist in this chart"];
        }

        if (! $this->serves($account, $type) || $this->normalize($account->name) !== $this->normalize($name)) {
            return [null, "{$code} is {$account->name}, not an active, postable {$type} account named {$name}"];
        }

        return [$account, null];
    }

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

            return $this->serves($account, $spec['type'])
                ? [$account, null]
                : [null, "{$account->code} {$account->name} is not an active, postable {$spec['type']} account"];
        }

        $taken = DB::table('accounting_accounts')->where('code', $spec['code'])->first();

        return $taken !== null ? [null, "{$spec['code']} is already used by {$taken->name}"] : [null, null];
    }

    private function serves(object $account, string $type): bool
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

    private function create(string $role, array $spec): object
    {
        $now = now();
        $parent = DB::table('accounting_accounts')->where('code', $spec['parent'])->where('type', $spec['type'])->where('is_group', true)->value('id');

        $id = DB::table('accounting_accounts')->insertGetId([
            'code' => $spec['code'],
            'name' => $spec['name'],
            'type' => $spec['type'],
            'normal_balance' => $spec['normal_balance'],
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
            'description' => 'System change: the '.self::LABELS[$role].' role ('.self::PURPOSES[$role]
                .") now posts to {$account->code} {$account->name}, ".self::DECIDED.'.',
            'created_at' => $now,
        ]);
    }
};
