<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank reconciliation per branch (owner answer 9, 2026-11-11): each money
 * account (one carrying a `cash_kind`) belongs to a branch, and that branch's
 * staff reconcile it.
 *
 * Nullable, because an account that holds no money belongs to no branch, and
 * a money account whose branch could not be decided stays visible to
 * `branches:view_all` only. Existing money accounts get the main branch from
 * the next migration. restrictOnDelete: an account's branch decides which
 * journal lines its reconciliations prove, so the branch can't vanish under
 * it.
 *
 * Safe to run twice: the column is added only when missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('accounting_accounts', 'branch_id')) {
            return;
        }

        Schema::table('accounting_accounts', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('cash_kind')
                ->constrained('branches')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('accounting_accounts', 'branch_id')) {
            return;
        }

        Schema::table('accounting_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
