<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * Spelled out rather than read off AccountingRolesBackfill and the
     * accounting models, as 2026_11_08_100000 is: this migration has to write
     * on its last deployment exactly what it wrote on its first. Polymorphic
     * rows store the full class name; there is no morph map.
     */
    private const AUDIT_ACCOUNT_ADDED = 'posting_role_account_added';

    private const AUDIT_ROLE_MAPPED = 'posting_role_mapped';

    private const ACCOUNT_TYPE = 'App\Models\AccountingAccount';

    private const MAPPING_TYPE = 'App\Models\AccountingAccountMapping';

    private const ROLE = 'interbranch_clearing';

    private const ACCOUNT = ['code' => '1190', 'name' => 'Interbranch Clearing', 'type' => 'asset', 'parent' => '1000', 'is_contra' => false, 'normal_balance' => 'debit', 'cash_flow_category' => 'financing'];

    private const PURPOSE = 'money moved between branches';

    private const DECIDED = 'as approved in the Phase 6 plan on 2026-11-19';

    /**
     * Data fix 3 (Phase 6 MB-BE5, multi-branch decision 9): give a chart
     * seeded before Phase 6 the 1190 Interbranch Clearing account and the
     * `interbranch_clearing` posting role that interbranch transfers post
     * through.
     *
     * The same account, role, skips and audit rows as AccountingRolesBackfill
     * for this role (its docblock explains them), written with the query
     * builder alone. InterbranchClearingBackfillTest holds the two to the same
     * outcome. `php artisan accounting:backfill-posting-roles --dry-run` shows
     * what this will change on a database before it is deployed there.
     *
     * - No chart: nothing written.
     * - The role already mapped: left alone, so a second run changes nothing.
     * - An account already named Interbranch Clearing (case, spaces and
     *   punctuation ignored) that can serve the role: mapped, no new account.
     * - Two such names, one that can't serve the role, or code 1190 taken by
     *   another name: skipped and listed with the reason; transfers are then
     *   refused until someone sets the role in Accounting → Settings →
     *   Default Accounts.
     *
     * Each account added and each role mapped writes one audit row as a
     * system change (no user). Posted journals are never touched.
     */
    public function up(): void
    {
        $result = ['chart' => false, 'created' => [], 'mapped' => [], 'kept' => [], 'skipped' => []];

        if (DB::table('accounting_accounts')->exists()) {
            $result['chart'] = true;

            DB::transaction(function () use (&$result): void {
                $this->handle($result);
            });
        }

        $summary = $result['chart']
            ? sprintf(
                'interbranch clearing: %d account(s) added, %d role(s) mapped, %d already mapped, %d skipped',
                count($result['created']),
                count($result['mapped']),
                count($result['kept']),
                count($result['skipped']),
            )
            : 'interbranch clearing: no chart of accounts, nothing to add';

        Log::info($summary, $result);

        if ($result['skipped'] !== []) {
            Log::warning('Interbranch clearing left unmapped; set it in Accounting → Settings → Default Accounts.', [
                'rows' => $result['skipped'],
            ]);
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    /**
     * Nothing to undo. Removing the account or the role again would refuse
     * every interbranch transfer, and an account that has been posted to
     * cannot be removed. Each change is on record in the audit log.
     */
    public function down(): void {}

    /**
     * @param  array{chart: bool, created: list<mixed>, mapped: list<mixed>, kept: list<mixed>, skipped: list<mixed>}  $result
     */
    private function handle(array &$result): void
    {
        $mapped = DB::table('accounting_account_mappings')->where('role', self::ROLE)->first();

        if ($mapped !== null) {
            $result['kept'][] = [
                'role' => self::ROLE,
                'code' => DB::table('accounting_accounts')->where('id', $mapped->accounting_account_id)->value('code'),
            ];

            return;
        }

        [$account, $reason] = $this->byName(self::ACCOUNT);

        if ($reason !== null) {
            $result['skipped'][] = ['role' => self::ROLE, 'reason' => $reason];

            return;
        }

        if ($account === null) {
            $result['created'][] = ['role' => self::ROLE, 'code' => self::ACCOUNT['code'], 'name' => self::ACCOUNT['name'], 'type' => self::ACCOUNT['type']];
            $account = $this->create(self::ACCOUNT);
        }

        $result['mapped'][] = ['role' => self::ROLE, 'code' => $account->code, 'name' => $account->name];

        $this->map($account);
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
    private function create(array $spec): object
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
            'description' => "System change: added account {$spec['code']} {$spec['name']} ({$spec['type']}) for ".self::PURPOSE.', '.self::DECIDED.'.',
            'created_at' => $now,
        ]);

        return (object) ['id' => $id, 'code' => $spec['code'], 'name' => $spec['name']];
    }

    private function map(object $account): void
    {
        $now = now();

        $id = DB::table('accounting_account_mappings')->insertGetId([
            'role' => self::ROLE,
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
            'new_values' => json_encode(['role' => self::ROLE, 'account_code' => $account->code, 'account_name' => $account->name]),
            'ip_address' => null,
            'user_agent' => null,
            'description' => 'System change: role '.self::ROLE.' ('.self::PURPOSE.") now posts to {$account->code} {$account->name}, ".self::DECIDED.'.',
            'created_at' => $now,
        ]);
    }
};
