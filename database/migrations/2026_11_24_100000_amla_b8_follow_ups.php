<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMLA-B8 follow-ups (Phase 6):
 * - `amla_exports.file_deleted_at`, set once when the sweep deletes an
 *   export's file (or finds it gone), indexed with `created_at`, so each run
 *   reads only expired exports whose file it hasn't removed yet, however long
 *   the scheduler was stopped;
 * - `amla_watchlist_versions.version_label` may be empty: a version uploaded
 *   without a label (and none in its file) keeps none, and screens read
 *   "Version #n". Existing labels stay as they are.
 * - `amla_alerts.counted_amount` and `counted_transactions`: the figures the
 *   alert's reason prints, set by its rule when it opened (null when the
 *   reason prints none), apart from every transaction that joined it later.
 *   New alerts only: past alerts keep null, nothing is back-filled.
 *
 * Nothing outside AMLA is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amla_exports', function (Blueprint $table) {
            $table->timestamp('file_deleted_at')->nullable()->after('user_id');
            $table->index(['file_deleted_at', 'created_at']);
        });

        Schema::table('amla_watchlist_versions', function (Blueprint $table) {
            $table->string('version_label', 150)->nullable()->change();
        });

        Schema::table('amla_alerts', function (Blueprint $table) {
            $table->decimal('counted_amount', 15, 2)->nullable()->after('indicators');
            $table->unsignedInteger('counted_transactions')->nullable()->after('counted_amount');
        });
    }

    public function down(): void
    {
        if (DB::table('amla_watchlist_versions')->whereNull('version_label')->exists()
            || DB::table('amla_alerts')->whereNotNull('counted_amount')->exists()
            || DB::table('amla_exports')->whereNotNull('file_deleted_at')->exists()) {
            throw new RuntimeException('AMLA records are kept forever, so the AMLA-B8 columns are not removed once used.');
        }

        Schema::table('amla_alerts', function (Blueprint $table) {
            $table->dropColumn(['counted_amount', 'counted_transactions']);
        });

        Schema::table('amla_watchlist_versions', function (Blueprint $table) {
            $table->string('version_label', 150)->nullable(false)->change();
        });

        Schema::table('amla_exports', function (Blueprint $table) {
            $table->dropIndex(['file_deleted_at', 'created_at']);
            $table->dropColumn('file_deleted_at');
        });
    }
};
