<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The branch where a walk-in GCash transaction is done (O4 review,
 * 2026-11-11), so its journal posts there and that branch's Cash and GCash
 * reconciliations count it.
 *
 * Nullable: a member's transaction keeps posting in the member's branch and
 * leaves this empty, a head office walk-in has none, and existing rows stay as
 * they are (their journals already posted with no branch). New records only;
 * no data fix. restrictOnDelete: a branch with transactions has history.
 *
 * Safe to run twice: the column is added only when missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('gcash_transactions', 'branch_id')) {
            return;
        }

        Schema::table('gcash_transactions', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('gcash_non_member_id')
                ->constrained('branches')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('gcash_transactions', 'branch_id')) {
            return;
        }

        Schema::table('gcash_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
