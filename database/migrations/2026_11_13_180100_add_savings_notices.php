<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Savings notices (gap round S4, in-app only).
 *
 * - `savings_settings.notice_days`: the days before a time deposit matures
 *   on which a notice goes out (0 is the maturity day), [30, 15, 7, 3, 0] by
 *   default; `dormancy_warning_days`: how many days before an account would
 *   turn dormant it is listed as approaching and warned, 60 by default.
 * - `savings_notice_log`: one row per notice sent by the nightly job, keyed
 *   per account or time deposit and per day-mark (`notice_key`, unique), so a
 *   re-run or a second job sends nothing again.
 *
 * Added only when missing, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('savings_settings', 'notice_days')) {
            Schema::table('savings_settings', function (Blueprint $table): void {
                $table->json('notice_days')->nullable();
                $table->unsignedSmallInteger('dormancy_warning_days')->default(60);
            });

            DB::table('savings_settings')->whereNull('notice_days')->update(['notice_days' => json_encode([30, 15, 7, 3, 0])]);
        }

        if (! Schema::hasTable('savings_notice_log')) {
            Schema::create('savings_notice_log', function (Blueprint $table): void {
                $table->id();
                $table->string('notice_key', 120)->unique();
                $table->string('type', 40);
                $table->foreignId('account_id')->nullable()->constrained('savings_accounts')->restrictOnDelete();
                $table->foreignId('time_deposit_id')->nullable()->constrained('time_deposits')->restrictOnDelete();
                $table->date('sent_on');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_notice_log');

        if (Schema::hasColumn('savings_settings', 'notice_days')) {
            Schema::table('savings_settings', function (Blueprint $table): void {
                $table->dropColumn(['notice_days', 'dormancy_warning_days']);
            });
        }
    }
};
