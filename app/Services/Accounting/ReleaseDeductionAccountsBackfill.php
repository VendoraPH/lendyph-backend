<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use Illuminate\Support\Facades\DB;

/**
 * Give a chart of accounts seeded before 2026-10-03 the accounts and posting
 * roles the release deductions are booked to (PostingRules::DEDUCTION_ROLES),
 * as the cooperative's accountant confirmed them that day.
 *
 * ChartOfAccountsSeeder runs once and never over an existing chart, so a
 * deployment that already keeps books gets these from here. Without them a
 * release withholding one of these deductions would be refused: the posting
 * engine fails closed on an unmapped role.
 *
 * For each role, in this order:
 *
 * - `service_fee_income` → the existing 4040 Service Fee Income (code and name).
 * - `notarial_fees_payable` → 2030 Notarial Fees Payable, a new liability.
 * - `insurance_premium_payable` → 2040 Insurance Premium Payable, a new liability.
 * - `share_capital` → the chart's own equity account named "Share Capital",
 *   whatever its code, or a new 3060 Share Capital when it has none.
 * - `other_fee_income` → 4080 Other Fee Income, a new income account.
 *
 * A new account is looked for by its name anywhere in the chart first, so a
 * chart already carrying one under another code is mapped to it rather than
 * given a second.
 *
 * Every account must be active, postable (not a heading), not a contra
 * account, not a cash account, and of the role's type. A new account goes
 * under its type's heading (2000, 3000, 4000) when that heading exists.
 *
 * Writes only what it is sure of, and skips with the reason:
 *
 * - a role already mapped, so an administrator's choice is never changed and
 *   a second run changes nothing;
 * - a name carried by more than one account, or by one that cannot be posted
 *   to, and a code already taken by an account with another name;
 * - 4040 missing, unusable, or renamed;
 * - share capital when two equity accounts carry the name, or when equity
 *   accounts mention share capital but none is named exactly that, so it never
 *   adds a second share capital account next to the cooperative's own.
 *
 * A skipped role stays unmapped: a release withholding that deduction is
 * refused with a message naming the role until someone sets it in
 * Accounting → Settings → Default Accounts. Each account added and each role
 * mapped gets one audit row as a system change (no user). Posted journals are
 * never touched; only releases from then on use the new roles.
 *
 * The 2026_10_03_130000 migration does the same on deploy, written out with
 * the query builder so that it depends on no application class; keep the two
 * in step (ReleaseDeductionAccountsBackfillTest compares them).
 * `php artisan accounting:backfill-release-deduction-accounts --dry-run`
 * shows what it will change on a database first.
 */
class ReleaseDeductionAccountsBackfill
{
    public const AUDIT_ACCOUNT_ADDED = 'release_deduction_account_added';

    public const AUDIT_ROLE_MAPPED = 'release_deduction_role_mapped';

    /**
     * The role each new account is for, in the order the roles are handled.
     *
     * @var array<string, array{code: string, name: string, type: string, parent: string, cash_flow_category: string}>
     */
    public const NEW_ACCOUNTS = [
        'notarial_fees_payable' => ['code' => '2030', 'name' => 'Notarial Fees Payable', 'type' => 'liability', 'parent' => '2000', 'cash_flow_category' => 'financing'],
        'insurance_premium_payable' => ['code' => '2040', 'name' => 'Insurance Premium Payable', 'type' => 'liability', 'parent' => '2000', 'cash_flow_category' => 'financing'],
        'share_capital' => ['code' => '3060', 'name' => 'Share Capital', 'type' => 'equity', 'parent' => '3000', 'cash_flow_category' => 'financing'],
        'other_fee_income' => ['code' => '4080', 'name' => 'Other Fee Income', 'type' => 'income', 'parent' => '4000', 'cash_flow_category' => 'operating'],
    ];

    /** The existing account `service_fee_income` points at. */
    public const SERVICE_FEE_CODE = '4040';

    private const ROLES = ['service_fee_income', 'notarial_fees_payable', 'insurance_premium_payable', 'share_capital', 'other_fee_income'];

    private const DEDUCTIONS = [
        'service_fee_income' => 'service fees',
        'notarial_fees_payable' => 'notarial fees',
        'insurance_premium_payable' => 'insurance premiums',
        'share_capital' => 'share capital',
        'other_fee_income' => 'catalog fees',
    ];

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
            return 'release deduction accounts: no chart of accounts, nothing to add';
        }

        return sprintf(
            'release deduction accounts: %d account(s) added, %d role(s) mapped, %d already mapped, %d skipped',
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

        [$account, $reason] = match ($role) {
            'service_fee_income' => $this->existing(self::SERVICE_FEE_CODE, 'Service Fee Income', 'income'),
            'share_capital' => $this->shareCapital(),
            default => $this->byCode(self::NEW_ACCOUNTS[$role]),
        };

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
     * postable, of the role's type, and still carrying its name. A 4040 an
     * administrator renamed for something else is not taken on its code alone.
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
     * "Notarial Fees Payable" under another code never gets a second one:
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

    /**
     * The chart's own share capital account, or none (3060 is to be created),
     * or a reason the choice is not certain.
     *
     * @return array{0: object|null, 1: string|null}
     */
    private function shareCapital(): array
    {
        $equity = DB::table('accounting_accounts')->where('type', 'equity')->orderBy('code')->get();

        $named = $equity->filter(fn (object $account): bool => $this->postable($account, 'equity')
            && $this->normalize($account->name) === 'share capital')->values();

        if ($named->count() > 1) {
            return [null, 'more than one equity account is named Share Capital ('.$named->pluck('code')->implode(', ').')'];
        }

        if ($named->count() === 1) {
            return [$named->first(), null];
        }

        $mentioning = $equity->filter(fn (object $account): bool => str_contains($this->normalize($account->name), 'share capital'));

        if ($mentioning->isNotEmpty()) {
            return [null, 'equity accounts mention share capital ('.$mentioning->map(fn (object $account): string => "{$account->code} {$account->name}")->implode(', ').') but none is an active, postable account named Share Capital'];
        }

        return $this->byCode(self::NEW_ACCOUNTS['share_capital']);
    }

    private function postable(object $account, string $type): bool
    {
        return $account->type === $type
            && (bool) $account->is_active
            && ! (bool) $account->is_group
            && ! (bool) $account->is_contra
            && $account->cash_kind === null;
    }

    /** FeeOverlapDetector's normal form: lower case, runs of other characters as one space. */
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
            'auditable_type' => AccountingAccount::class,
            'auditable_id' => $id,
            'old_values' => null,
            'new_values' => json_encode(['code' => $spec['code'], 'name' => $spec['name'], 'type' => $spec['type'], 'parent_code' => $parent === null ? null : $spec['parent']]),
            'ip_address' => null,
            'user_agent' => null,
            'description' => "System change: added account {$spec['code']} {$spec['name']} ({$spec['type']}) for ".self::DEDUCTIONS[$role].' withheld at release, as the accountant confirmed on 2026-10-03.',
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
            'description' => "System change: {$this->deduction($role)} withheld at release now post to {$account->code} {$account->name} (role {$role}), as the accountant confirmed on 2026-10-03.",
            'created_at' => $now,
        ]);
    }

    private function deduction(string $role): string
    {
        return ucfirst(self::DEDUCTIONS[$role]);
    }
}
