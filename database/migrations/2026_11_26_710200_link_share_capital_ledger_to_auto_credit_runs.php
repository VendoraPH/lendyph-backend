<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Share capital auto-credit rows carry their run (finish run round 7, money
 * review), so a run tells its own rows from entries by hand by something staff
 * can't type. New runs only:
 *
 * - `share_capital_ledger.auto_credit_run_id`: set only by the run. Past rows
 *   keep null.
 * - `auto_credit_runs.links_rows`: true on runs that link their rows. Past
 *   runs keep false; their rows are still told apart by the run's fixed
 *   description, up to the last such run.
 *
 * Only adds, each step skipped when already there, so running it again
 * changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('share_capital_ledger', 'auto_credit_run_id')) {
            Schema::table('share_capital_ledger', function (Blueprint $table): void {
                $table->foreignId('auto_credit_run_id')->nullable()->after('savings_transaction_id')
                    ->constrained('auto_credit_runs')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('auto_credit_runs', 'links_rows')) {
            Schema::table('auto_credit_runs', function (Blueprint $table): void {
                $table->boolean('links_rows')->default(false)->after('skipped_count');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('auto_credit_runs', 'links_rows')) {
            Schema::table('auto_credit_runs', function (Blueprint $table): void {
                $table->dropColumn('links_rows');
            });
        }

        if (Schema::hasColumn('share_capital_ledger', 'auto_credit_run_id')) {
            Schema::table('share_capital_ledger', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('auto_credit_run_id');
            });
        }
    }
};
