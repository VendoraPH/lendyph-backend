<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The penalty configuration a loan was created under, copied from its
     * product's active configuration at creation, the same point
     * `penalty_rate` is copied (PenaltyTerms::forProduct()).
     *
     * - `penalty_config_id`: which configuration. Deleting a configuration a
     *   loan references is refused (409 in PenaltyConfigService, and this key
     *   restricts it).
     * - `penalty_config_name`: its name on the day the loan was created.
     * - `penalty_brackets`: its brackets on that day,
     *   `[{days_from, days_to, rate}]`. Charges read these, never the live
     *   configuration, so a later edit never reprices the loan.
     *
     * All null on every existing loan, which keeps its `penalty_rate`. No
     * backfill. Only adds; safe to run twice.
     */
    public function up(): void
    {
        if (Schema::hasColumn('loans', 'penalty_config_id')) {
            return;
        }

        Schema::table('loans', function (Blueprint $table): void {
            $table->foreignId('penalty_config_id')->nullable()->after('penalty_rate')->constrained()->restrictOnDelete();
            $table->string('penalty_config_name', 100)->nullable()->after('penalty_config_id');
            $table->json('penalty_brackets')->nullable()->after('penalty_config_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('loans', 'penalty_config_id')) {
            return;
        }

        Schema::table('loans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('penalty_config_id');
            $table->dropColumn(['penalty_config_name', 'penalty_brackets']);
        });
    }
};
