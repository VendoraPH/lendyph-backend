<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMLA-B8 follow-ups (Phase 6):
 * - an index on `amla_exports.created_at`, the export sweep's range (it
 *   looks only at exports that expired in the last day);
 * - `amla_watchlist_versions.version_label` may be empty: a version uploaded
 *   without a label (and none in its file) keeps none, and screens read
 *   "Version #n". Existing labels stay as they are.
 * - `amla_alerts.counted_amount` and `counted_transactions`: what the rule
 *   counted when the alert opened, the figure its reason states, apart from
 *   every transaction that joined it later. New alerts only: past alerts
 *   keep null, nothing is back-filled.
 *
 * Nothing outside AMLA is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amla_exports', function (Blueprint $table) {
            $table->index('created_at');
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
            || DB::table('amla_alerts')->whereNotNull('counted_amount')->exists()) {
            throw new RuntimeException('AMLA records are kept forever, so the AMLA-B8 columns are not removed once used.');
        }

        Schema::table('amla_alerts', function (Blueprint $table) {
            $table->dropColumn(['counted_amount', 'counted_transactions']);
        });

        Schema::table('amla_watchlist_versions', function (Blueprint $table) {
            $table->string('version_label', 150)->nullable(false)->change();
        });

        Schema::table('amla_exports', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
