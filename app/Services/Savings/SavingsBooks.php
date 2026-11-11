<?php

namespace App\Services\Savings;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use App\Services\Accounting\PostingRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Savings needs books (design §0, §4). This class answers two questions:
 *
 * - **Can a product be turned on?** Only when a chart of accounts exists and
 *   every posting role its kind posts to is mapped ({@see self::assertCanActivate()}).
 *   Fails closed: a savings product that moved money without a journal would
 *   leave the subledger and the general ledger disagreeing.
 * - **Turning savings on for a chart** (`savings:prepare-books [--dry-run]`,
 *   {@see self::prepare()}): add any missing 2050 Savings Deposits, 2060 Time
 *   Deposits and 5160 Interest Expense on Deposits, and map the four roles.
 *   `withholding_tax_deposits` goes to the account the chart already maps
 *   for `withholding_tax_payable` (2210 Withholding Tax Payable on a seeded
 *   chart), else 2210 itself.
 *
 * The same rules as AccountingRolesBackfill: a new account is looked for by
 * its NAME anywhere in the chart first; it must be active, postable, not a
 * contra or cash account and of the role's type; a role already mapped is
 * kept (an administrator's choice is never changed); anything uncertain is
 * skipped with the reason. Each account added and each role mapped writes one
 * audit row: a system change (no user) from the command and the migration, or
 * in the name of the staff member who pressed "Set up savings in the books"
 * in Savings Settings. No chart, nothing written. Posted journals are never
 * touched.
 *
 * The 2026_11_10_500200 migration does the same on deploy, written with the
 * query builder alone, and only on a chart that already has 2050 (the
 * cooperative template): a lending company's chart is left alone until
 * someone runs the command. PrepareBooksTest holds the two to one outcome.
 */
class SavingsBooks
{
    public const ROLES = PostingRules::SAVINGS_ROLES;

    public const AUDIT_ACCOUNT_ADDED = 'savings_books_account_added';

    public const AUDIT_ROLE_MAPPED = 'savings_books_role_mapped';

    /**
     * The account each role gets when the chart has none.
     *
     * @var array<string, array{code: string, name: string, type: string, parent: string, normal_balance: string, cash_flow_category: string}>
     */
    public const NEW_ACCOUNTS = [
        'savings_deposits' => ['code' => '2050', 'name' => 'Savings Deposits', 'type' => 'liability', 'parent' => '2000', 'normal_balance' => 'credit', 'cash_flow_category' => 'financing'],
        'time_deposits' => ['code' => '2060', 'name' => 'Time Deposits', 'type' => 'liability', 'parent' => '2000', 'normal_balance' => 'credit', 'cash_flow_category' => 'financing'],
        'interest_expense_deposits' => ['code' => '5160', 'name' => 'Interest Expense on Deposits', 'type' => 'expense', 'parent' => '5000', 'normal_balance' => 'debit', 'cash_flow_category' => 'operating'],
    ];

    /** The existing account `withholding_tax_deposits` falls back to: [code, name, type]. */
    public const WITHHOLDING_TAX = ['2210', 'Withholding Tax Payable', 'liability'];

    /** The role whose account `withholding_tax_deposits` follows first. */
    public const WITHHOLDING_TAX_FOLLOWS = 'withholding_tax_payable';

    /**
     * The roles a product of each kind posts to.
     *
     * @var array<string, list<string>>
     */
    public const ROLES_BY_KIND = [
        'regular' => ['savings_deposits', 'interest_expense_deposits', 'withholding_tax_deposits'],
        'time_deposit' => ['savings_deposits', 'time_deposits', 'interest_expense_deposits', 'withholding_tax_deposits'],
    ];

    private const PURPOSES = [
        'savings_deposits' => 'members\' savings balances',
        'time_deposits' => 'members\' time deposits',
        'interest_expense_deposits' => 'interest paid on savings and time deposits',
        'withholding_tax_deposits' => 'tax withheld from deposit interest',
    ];

    private const DECIDED = 'as approved in the Phase 3 plan on 2026-11-10';

    /**
     * The roles this kind of product needs that have no account, or null
     * when there is no chart of accounts at all.
     *
     * @return list<string>|null
     */
    public function missingRoles(string $kind): ?array
    {
        if (! DB::table('accounting_accounts')->exists()) {
            return null;
        }

        $mapped = DB::table('accounting_account_mappings')->whereIn('role', self::ROLES)->pluck('role')->all();

        return array_values(array_diff(self::ROLES_BY_KIND[$kind] ?? self::ROLES, $mapped));
    }

    /**
     * Refuses (422 on `is_active`) to turn on a product of this kind while
     * there is no chart or one of its roles is unmapped.
     *
     * @throws ValidationException
     */
    public function assertCanActivate(string $kind): void
    {
        $missing = $this->missingRoles($kind);

        if ($missing === null) {
            throw ValidationException::withMessages(['is_active' => [
                'The product can\'t be turned on yet: savings needs books, and there is no chart of accounts. '
                .'Set one up in Accounting → Chart of Accounts first.',
            ]]);
        }

        if ($missing !== []) {
            $one = count($missing) === 1;

            throw ValidationException::withMessages(['is_active' => [
                'The product can\'t be turned on yet: the '.AccountingAccountMapping::labelList($missing)
                .' posting '.($one ? 'role has' : 'roles have').' no account. Set up savings in the books from Savings Settings first.',
            ]]);
        }
    }

    /**
     * Add and map everything that can be, or with `$dryRun` only say what.
     * `$userId` is who asked, written on the audit rows; null is the system
     * (the command and the migration).
     *
     * @return array{
     *     chart: bool,
     *     created: list<array{role: string, code: string, name: string, type: string}>,
     *     mapped: list<array{role: string, code: string, name: string}>,
     *     kept: list<array{role: string, code: string|null}>,
     *     skipped: list<array{role: string, reason: string}>,
     * }
     */
    public function prepare(bool $dryRun, ?int $userId = null): array
    {
        $result = ['chart' => false, 'created' => [], 'mapped' => [], 'kept' => [], 'skipped' => []];

        if (! DB::table('accounting_accounts')->exists()) {
            return $result;
        }

        $result['chart'] = true;

        $work = function () use ($dryRun, $userId, &$result): void {
            foreach (self::ROLES as $role) {
                $this->handle($role, $dryRun, $userId, $result);
            }
        };

        $dryRun ? $work() : DB::transaction($work);

        return $result;
    }

    /**
     * @param  array{chart: bool, created: list<mixed>, mapped: list<mixed>, kept: list<mixed>, skipped: list<mixed>}  $result
     */
    public static function summary(array $result): string
    {
        if (! $result['chart']) {
            return 'savings books: no chart of accounts, nothing to add';
        }

        return sprintf(
            'savings books: %d account(s) added, %d role(s) mapped, %d already mapped, %d skipped',
            count($result['created']),
            count($result['mapped']),
            count($result['kept']),
            count($result['skipped']),
        );
    }

    /**
     * @param  array{chart: bool, created: list<mixed>, mapped: list<mixed>, kept: list<mixed>, skipped: list<mixed>}  $result
     */
    private function handle(string $role, bool $dryRun, ?int $userId, array &$result): void
    {
        $mapped = DB::table('accounting_account_mappings')->where('role', $role)->first();

        if ($mapped !== null) {
            $result['kept'][] = [
                'role' => $role,
                'code' => DB::table('accounting_accounts')->where('id', $mapped->accounting_account_id)->value('code'),
            ];

            return;
        }

        [$account, $reason] = $role === 'withholding_tax_deposits'
            ? $this->withholdingTaxAccount()
            : $this->byName(self::NEW_ACCOUNTS[$role]);

        if ($reason !== null) {
            $result['skipped'][] = ['role' => $role, 'reason' => $reason];

            return;
        }

        if ($account === null) {
            $spec = self::NEW_ACCOUNTS[$role];
            $result['created'][] = ['role' => $role, 'code' => $spec['code'], 'name' => $spec['name'], 'type' => $spec['type']];
            $account = $dryRun ? (object) ['id' => null, 'code' => $spec['code'], 'name' => $spec['name']] : $this->create($role, $spec, $userId);
        }

        $result['mapped'][] = ['role' => $role, 'code' => $account->code, 'name' => $account->name];

        if (! $dryRun) {
            $this->map($role, $account, $userId);
        }
    }

    /**
     * The account `withholding_tax_payable` already points at, when it can
     * serve; otherwise 2210 Withholding Tax Payable, by code and name.
     *
     * @return array{0: object|null, 1: string|null}
     */
    private function withholdingTaxAccount(): array
    {
        $followed = DB::table('accounting_account_mappings')->where('role', self::WITHHOLDING_TAX_FOLLOWS)->value('accounting_account_id');
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

    /**
     * @param  array{code: string, name: string, type: string}  $spec
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

            return $this->serves($account, $spec['type'])
                ? [$account, null]
                : [null, "{$account->code} {$account->name} is not an active, postable {$spec['type']} account"];
        }

        $taken = DB::table('accounting_accounts')->where('code', $spec['code'])->first();

        if ($taken !== null) {
            return [null, "{$spec['code']} is already used by {$taken->name}"];
        }

        return [null, null];
    }

    private function serves(object $account, string $type): bool
    {
        return $account->type === $type
            && (bool) $account->is_active
            && ! (bool) $account->is_group
            && ! (bool) $account->is_contra
            && $account->cash_kind === null;
    }

    /** An audit description: "System change: …" for the system, else the sentence itself. */
    private function said(?int $userId, string $sentence): string
    {
        return $userId === null ? "System change: {$sentence}" : ucfirst($sentence);
    }

    private function normalize(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($name)) ?? '');
    }

    /**
     * @param  array{code: string, name: string, type: string, parent: string, normal_balance: string, cash_flow_category: string}  $spec
     */
    private function create(string $role, array $spec, ?int $userId): object
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
            'is_contra' => false,
            'parent_id' => $parent,
            'is_group' => false,
            'is_active' => true,
            'cash_kind' => null,
            'cash_flow_category' => $spec['cash_flow_category'],
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('audit_logs')->insert([
            'user_id' => $userId,
            'action' => self::AUDIT_ACCOUNT_ADDED,
            'auditable_type' => AccountingAccount::class,
            'auditable_id' => $id,
            'old_values' => null,
            'new_values' => json_encode(['code' => $spec['code'], 'name' => $spec['name'], 'type' => $spec['type'], 'parent_code' => $parent === null ? null : $spec['parent']]),
            'ip_address' => null,
            'user_agent' => null,
            'description' => $this->said($userId, "added account {$spec['code']} {$spec['name']} ({$spec['type']}) for ".self::PURPOSES[$role].', '.self::DECIDED.'.'),
            'created_at' => $now,
        ]);

        return (object) ['id' => $id, 'code' => $spec['code'], 'name' => $spec['name']];
    }

    private function map(string $role, object $account, ?int $userId): void
    {
        $now = now();

        $id = DB::table('accounting_account_mappings')->insertGetId([
            'role' => $role,
            'accounting_account_id' => $account->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('audit_logs')->insert([
            'user_id' => $userId,
            'action' => self::AUDIT_ROLE_MAPPED,
            'auditable_type' => AccountingAccountMapping::class,
            'auditable_id' => $id,
            'old_values' => null,
            'new_values' => json_encode(['role' => $role, 'account_code' => $account->code, 'account_name' => $account->name]),
            'ip_address' => null,
            'user_agent' => null,
            'description' => $this->said($userId, 'the '.AccountingAccountMapping::label($role).' role ('.self::PURPOSES[$role]
                .") now posts to {$account->code} {$account->name}, ".self::DECIDED.'.'),
            'created_at' => $now,
        ]);
    }
}
