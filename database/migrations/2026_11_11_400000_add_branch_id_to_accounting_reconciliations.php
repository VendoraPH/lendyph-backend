<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank reconciliation per account per branch (owner answer 9 and the lead's
 * decision, 2026-11-11).
 *
 * Money accounts stay organisation-wide, because one posting mapping serves
 * every branch. A reconciliation instead carries the branch whose journal
 * lines it proves, picked when it starts. Null means "all branches": every
 * line on the account, which is what every reconciliation counted before, so
 * existing rows keep null and their figures don't change. No data fix.
 *
 * One reconciliation per account, branch and period. MySQL treats NULLs in a
 * unique index as distinct, so the index reads `branch_scope`, a stored column
 * holding the branch id or 0 for "all branches". The new index is created
 * before the old (account, period) one is dropped, so the account's foreign key
 * always has an index to stand on.
 *
 * restrictOnDelete: a branch with reconciliations has history.
 *
 * Safe to run twice: each step checks whether it has already run.
 */
return new class extends Migration
{
    private const OLD_UNIQUE = 'accounting_reconciliations_account_period_unique';

    private const NEW_UNIQUE = 'acct_recon_account_branch_period_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('accounting_reconciliations', 'branch_id')) {
            Schema::table('accounting_reconciliations', function (Blueprint $table) {
                $table->foreignId('branch_id')
                    ->nullable()
                    ->after('accounting_account_id')
                    ->constrained('branches')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('accounting_reconciliations', 'branch_scope')) {
            Schema::table('accounting_reconciliations', function (Blueprint $table) {
                $table->unsignedBigInteger('branch_scope')->storedAs('coalesce(`branch_id`, 0)')->after('branch_id');
            });
        }

        if (! Schema::hasIndex('accounting_reconciliations', self::NEW_UNIQUE)) {
            Schema::table('accounting_reconciliations', function (Blueprint $table) {
                $table->unique(['accounting_account_id', 'branch_scope', 'period'], self::NEW_UNIQUE);
            });
        }

        if (Schema::hasIndex('accounting_reconciliations', self::OLD_UNIQUE)) {
            Schema::table('accounting_reconciliations', function (Blueprint $table) {
                $table->dropUnique(self::OLD_UNIQUE);
            });
        }
    }

    /**
     * Back to one reconciliation per account and period. Refused, before
     * anything changes, while a period has been reconciled more than once
     * (per branch, or per branch and for all branches): the old index can't
     * hold both, and dropping one would lose its statement and matches.
     */
    public function down(): void
    {
        $twice = DB::table('accounting_reconciliations as r')
            ->join('accounting_accounts as a', 'a.id', '=', 'r.accounting_account_id')
            ->groupBy('r.accounting_account_id', 'a.code', 'a.name', 'r.period')
            ->havingRaw('count(*) > 1')
            ->orderBy('a.code')
            ->orderBy('r.period')
            ->get(['a.code', 'a.name', 'r.period']);

        if ($twice->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot roll back the per-branch reconciliation change: '
                .$twice->map(fn (object $row): string => "{$row->code} {$row->name} has more than one reconciliation for {$row->period}")->implode('; ')
                .'. The old rule allows one per account and period, so the extra ones would have to go, with their '
                .'statement lines and matches. Nothing was changed.',
            );
        }

        if (! Schema::hasIndex('accounting_reconciliations', self::OLD_UNIQUE)) {
            Schema::table('accounting_reconciliations', function (Blueprint $table) {
                $table->unique(['accounting_account_id', 'period'], self::OLD_UNIQUE);
            });
        }

        if (Schema::hasIndex('accounting_reconciliations', self::NEW_UNIQUE)) {
            Schema::table('accounting_reconciliations', function (Blueprint $table) {
                $table->dropUnique(self::NEW_UNIQUE);
            });
        }

        if (Schema::hasColumn('accounting_reconciliations', 'branch_scope')) {
            Schema::table('accounting_reconciliations', function (Blueprint $table) {
                $table->dropColumn('branch_scope');
            });
        }

        if (Schema::hasColumn('accounting_reconciliations', 'branch_id')) {
            Schema::table('accounting_reconciliations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('branch_id');
            });
        }
    }
};
