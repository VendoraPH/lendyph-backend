<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The branch whose books a share capital row posted in (Phase 6 MB-BE3, D4,
 * decision 16). ShareCapitalLedger stamps it when the row is written: the
 * loan's branch for a release or a payment's build-up, the savings account's
 * for a transfer from savings, otherwise the member's. A member who later
 * moves branch takes no past row along.
 *
 * Nullable: rows written before this have none until
 * `branches:backfill-attribution` (run once by the next migration) proves
 * one from their posted journal; until then the reports count them in the
 * member's branch (BranchAttribution). Restrict on delete, as for loans and
 * savings: a branch that holds money rows is never removed under them.
 * Indexed with the date for the by-branch reports. Added only when missing,
 * so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('share_capital_ledger', 'branch_id')) {
            return;
        }

        Schema::table('share_capital_ledger', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('borrower_id')
                ->constrained('branches')->restrictOnDelete();
            $table->index(['branch_id', 'date'], 'share_capital_ledger_branch_date_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('share_capital_ledger', 'branch_id')) {
            return;
        }

        Schema::table('share_capital_ledger', function (Blueprint $table): void {
            $table->dropIndex('share_capital_ledger_branch_date_index');
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
