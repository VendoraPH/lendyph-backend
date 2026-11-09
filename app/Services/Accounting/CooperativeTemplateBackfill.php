<?php

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingAccountMapping;
use Illuminate\Support\Facades\DB;

/**
 * Give a cooperative that already keeps the lending company chart the
 * cooperative template's accounts (Phase 2, accounting round C, slice C1),
 * through `accounting:apply-cooperative-template`. Never through a migration:
 * a lending company must not get them.
 *
 * - Each row of ChartOfAccountsSeeder::COOPERATIVE_ADDITIONS, in code order
 *   (the 3070 heading before 3071–3074):
 *   - missing by code: created under its parent, as the template seeds it.
 *     A parent that is missing, isn't a heading of the row's type, or was
 *     itself skipped skips the row;
 *   - present with the same name, type and heading flag: left alone;
 *   - present with another name, type or heading flag: skipped with the
 *     reason. Never renamed.
 * - `net_surplus` moves from 3030 to 3080 Undivided Net Surplus only while it
 *   still points at 3030, no user ever changed it (no audit row with a user on
 *   that mapping), and 3080 is the template's account. Otherwise it is left
 *   and the reason given.
 *
 * Query builder only, in one transaction, like AccountingRolesBackfill. Each
 * account added and the re-point get one audit row as a system change (no
 * user). Posted journals are never touched. A second run writes nothing.
 */
class CooperativeTemplateBackfill
{
    public const AUDIT_ACCOUNT_ADDED = 'cooperative_template_account_added';

    public const AUDIT_ROLE_REPOINTED = 'cooperative_template_role_repointed';

    /** The role the template re-points, from and to. */
    private const ROLE = 'net_surplus';

    private const FROM_CODE = '3030';

    private const DECIDED = 'for the cooperative chart template, as decided in accounting round C on 2026-11-09';

    /**
     * Add every row that can be and re-point `net_surplus`, or with `$dryRun`
     * only say what.
     *
     * @return array{
     *     chart: bool,
     *     created: list<array{code: string, name: string, type: string, parent: string}>,
     *     present: list<array{code: string, name: string}>,
     *     skipped: list<array{code: string, reason: string}>,
     *     mapping: array{status: string, from?: string, to?: string, reason?: string}|null,
     * }
     */
    public function run(bool $dryRun): array
    {
        $result = ['chart' => false, 'created' => [], 'present' => [], 'skipped' => [], 'mapping' => null];

        if (! DB::table('accounting_accounts')->exists()) {
            return $result;
        }

        $result['chart'] = true;

        $work = function () use ($dryRun, &$result): void {
            $usable = [];

            foreach (ChartOfAccountsSeeder::COOPERATIVE_ADDITIONS as $row) {
                $this->handle($row, $dryRun, $usable, $result);
            }

            $result['mapping'] = $this->repoint($dryRun, $usable);
        };

        $dryRun ? $work() : DB::transaction($work);

        return $result;
    }

    /**
     * @param  array{code: string, name: string, type: string, parent: string, is_group?: bool}  $row
     * @param  array<string, int|null>  $usable  code => id of each template row that is in place (null in a dry run)
     * @param  array<string, mixed>  $result
     */
    private function handle(array $row, bool $dryRun, array &$usable, array &$result): void
    {
        $isGroup = $row['is_group'] ?? false;
        $existing = DB::table('accounting_accounts')->where('code', $row['code'])->first();

        if ($existing !== null) {
            if ($this->normalize($existing->name) === $this->normalize($row['name'])
                && $existing->type === $row['type']
                && (bool) $existing->is_group === $isGroup) {
                $usable[$row['code']] = (int) $existing->id;
                $result['present'][] = ['code' => $row['code'], 'name' => $existing->name];

                return;
            }

            $kind = $isGroup ? "{$row['type']} heading" : "postable {$row['type']} account";
            $result['skipped'][] = [
                'code' => $row['code'],
                'reason' => "{$row['code']} is already {$existing->name} ({$existing->type}), not the {$kind} {$row['name']}; it is never renamed",
            ];

            return;
        }

        [$parentId, $reason] = $this->parent($row, $usable);

        if ($reason !== null) {
            $result['skipped'][] = ['code' => $row['code'], 'reason' => $reason];

            return;
        }

        $result['created'][] = ['code' => $row['code'], 'name' => $row['name'], 'type' => $row['type'], 'parent' => $row['parent']];
        $usable[$row['code']] = $dryRun ? null : $this->create($row, $parentId);
    }

    /**
     * The heading a new row goes under: a template row that is in place, or
     * an existing heading of the row's type.
     *
     * @param  array{code: string, name: string, type: string, parent: string}  $row
     * @param  array<string, int|null>  $usable
     * @return array{0: int|null, 1: string|null}
     */
    private function parent(array $row, array $usable): array
    {
        $code = $row['parent'];

        if (array_key_exists($code, $usable)) {
            return [$usable[$code], null];
        }

        if (in_array($code, array_column(ChartOfAccountsSeeder::COOPERATIVE_ADDITIONS, 'code'), true)) {
            return [null, "{$row['code']} {$row['name']} goes under {$code}, which was skipped"];
        }

        $parent = DB::table('accounting_accounts')->where('code', $code)->first();

        if ($parent === null) {
            return [null, "{$row['code']} {$row['name']} goes under {$code}, which is not in this chart"];
        }

        if (! (bool) $parent->is_group || $parent->type !== $row['type']) {
            return [null, "{$row['code']} {$row['name']} goes under {$code}, which is {$parent->name}, not a {$row['type']} heading"];
        }

        return [(int) $parent->id, null];
    }

    /**
     * Moving `net_surplus` from 3030 to 3080.
     *
     * @param  array<string, int|null>  $usable
     * @return array{status: string, from?: string, to?: string, reason?: string}
     */
    private function repoint(bool $dryRun, array $usable): array
    {
        $to = collect(ChartOfAccountsSeeder::COOPERATIVE_MAPPING_OVERRIDES)->get(self::ROLE);
        $mapping = DB::table('accounting_account_mappings')->where('role', self::ROLE)->first();

        if ($mapping === null) {
            return ['status' => 'skipped', 'reason' => 'it is not mapped; set it in Accounting → Settings → Default Accounts'];
        }

        $current = DB::table('accounting_accounts')->where('id', $mapping->accounting_account_id)->first(['id', 'code', 'name']);

        if ($current !== null && $current->code === $to) {
            return ['status' => 'kept', 'to' => $to];
        }

        if ($current === null || $current->code !== self::FROM_CODE) {
            return ['status' => 'skipped', 'reason' => 'it posts to '.($current === null ? 'an account that no longer exists' : "{$current->code} {$current->name}").", not {$this->fromLabel()}; an administrator's choice is never changed"];
        }

        $changedByAUser = DB::table('audit_logs')
            ->where('auditable_type', AccountingAccountMapping::class)
            ->where('auditable_id', $mapping->id)
            ->whereNotNull('user_id')
            ->exists();

        if ($changedByAUser) {
            return ['status' => 'skipped', 'reason' => "a user has changed it before; it stays on {$current->code} {$current->name}"];
        }

        if (! array_key_exists($to, $usable)) {
            return ['status' => 'skipped', 'reason' => "{$to} Undivided Net Surplus is not in place"];
        }

        if ($usable[$to] !== null && ! (bool) DB::table('accounting_accounts')->where('id', $usable[$to])->value('is_active')) {
            return ['status' => 'skipped', 'reason' => "{$to} Undivided Net Surplus is inactive"];
        }

        $target = $this->targetName($to);
        $from = "{$current->code} {$current->name}";

        if (! $dryRun) {
            $now = now();

            DB::table('accounting_account_mappings')
                ->where('id', $mapping->id)
                ->update(['accounting_account_id' => $usable[$to], 'updated_at' => $now]);

            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => self::AUDIT_ROLE_REPOINTED,
                'auditable_type' => AccountingAccountMapping::class,
                'auditable_id' => $mapping->id,
                'old_values' => json_encode(['role' => self::ROLE, 'account_code' => $current->code, 'account_name' => $current->name], JSON_UNESCAPED_UNICODE),
                'new_values' => json_encode(['role' => self::ROLE, 'account_code' => $to, 'account_name' => $target], JSON_UNESCAPED_UNICODE),
                'ip_address' => null,
                'user_agent' => null,
                'description' => 'System change: role '.self::ROLE." (the year-end close) now posts to {$to} {$target} instead of {$from}, ".self::DECIDED.'.',
                'created_at' => $now,
            ]);
        }

        return ['status' => 'repointed', 'from' => $from, 'to' => "{$to} {$target}"];
    }

    /**
     * @param  array{code: string, name: string, type: string, parent: string, is_group?: bool}  $row
     */
    private function create(array $row, ?int $parentId): int
    {
        $now = now();
        $isGroup = $row['is_group'] ?? false;

        $id = DB::table('accounting_accounts')->insertGetId([
            'code' => $row['code'],
            'name' => $row['name'],
            'type' => $row['type'],
            'normal_balance' => in_array($row['type'], ['asset', 'expense'], true) ? 'debit' : 'credit',
            'is_contra' => false,
            'parent_id' => $parentId,
            'is_group' => $isGroup,
            'is_active' => true,
            'cash_kind' => null,
            'cash_flow_category' => CashFlowCategories::defaultFor($row['type'], null),
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
            'new_values' => json_encode(['code' => $row['code'], 'name' => $row['name'], 'type' => $row['type'], 'is_group' => $isGroup, 'parent_code' => $row['parent']], JSON_UNESCAPED_UNICODE),
            'ip_address' => null,
            'user_agent' => null,
            'description' => "System change: added account {$row['code']} {$row['name']} ({$row['type']}".($isGroup ? ', heading' : '').') '.self::DECIDED.'.',
            'created_at' => $now,
        ]);

        return $id;
    }

    private function fromLabel(): string
    {
        return self::FROM_CODE.' Retained Earnings';
    }

    private function targetName(string $code): string
    {
        return (string) collect(ChartOfAccountsSeeder::COOPERATIVE_ADDITIONS)->firstWhere('code', $code)['name'];
    }

    /** FeeOverlapDetector's normal form: lower case, runs of other characters as one space. */
    private function normalize(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($name)) ?? '');
    }
}
