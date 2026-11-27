<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The AMLA foundation (Phase 6, AMLA-B1; AMLA design §4.6, §4.13–15):
 * - `amla_log_entries`: AMLA's own append-only log, kept forever, never the
 *   main audit trail;
 * - `amla_change_requests`: maker-checker requests; a change to the settings
 *   or the calendar applies only once a second `amla:settings` holder
 *   approves it. `pending_key` (unique while pending, NULL once decided)
 *   keeps one waiting request per target;
 * - `amla_settings_versions`: immutable settings versions, version 1 the
 *   documented defaults with every value "to verify";
 * - `amla_calendar_entries` and `amla_calendar_years`: the working-day
 *   calendar (holidays, AMLC non-reporting days, facility suspensions) and
 *   the years whose calendar is confirmed. `active_marker` is 1 while an
 *   entry is in force and NULL once removed, so one date holds one active
 *   entry of each kind.
 *
 * Users referenced here can't be deleted (restrict), like audit rows. No
 * money table changes and no data is touched outside these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amla_log_entries', function (Blueprint $table) {
            $table->id();
            $table->string('action', 40);
            $table->string('actor_type', 10);
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['action', 'id']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });

        Schema::create('amla_change_requests', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->string('status', 12)->default('pending');
            $table->string('pending_key', 80)->nullable()->unique();
            $table->json('payload');
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'type']);
        });

        Schema::create('amla_settings_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->json('values');
            $table->json('to_verify');
            $table->json('sources');
            $table->timestamp('effective_at');
            $table->foreignId('request_id')->nullable()->constrained('amla_change_requests')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('effective_at');
        });

        Schema::create('amla_calendar_entries', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('kind', 30);
            $table->string('name', 150);
            $table->string('source', 255);
            $table->unsignedTinyInteger('active_marker')->nullable()->default(1);
            $table->foreignId('request_id')->constrained('amla_change_requests')->restrictOnDelete();
            $table->foreignId('removal_request_id')->nullable()->constrained('amla_change_requests')->restrictOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['date', 'kind', 'active_marker']);
        });

        Schema::create('amla_calendar_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->timestamp('confirmed_at');
            $table->foreignId('request_id')->constrained('amla_change_requests')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        /*
         * Version 1: the documented starting points (AMLA design §4.6, §4.10,
         * §4.15), written out here so a later code change never rewrites what
         * a deployment started from. Every one is "to verify".
         */
        $defaults = [
            'ctr_threshold' => '500000.00',
            'ctr_cash_channels' => ['cash'],
            'ctr_deadline_working_days' => 5,
            'str_deadline_working_days' => 1,
            'review_years_high' => 1,
            'review_years_medium' => 2,
            'review_years_low' => 3,
        ];
        $now = now();

        DB::table('amla_settings_versions')->insert([
            'version' => 1,
            'values' => json_encode($defaults),
            'to_verify' => json_encode(array_keys($defaults)),
            'sources' => json_encode((object) []),
            'effective_at' => $now,
            'created_at' => $now,
        ]);
    }

    /**
     * AMLA records are kept forever: once the log holds a row, this refuses
     * rather than drop it.
     */
    public function down(): void
    {
        if (Schema::hasTable('amla_log_entries') && DB::table('amla_log_entries')->exists()) {
            throw new RuntimeException('The AMLA log holds records, which are kept forever, so the AMLA tables are not dropped.');
        }

        Schema::dropIfExists('amla_calendar_years');
        Schema::dropIfExists('amla_calendar_entries');
        Schema::dropIfExists('amla_settings_versions');
        Schema::dropIfExists('amla_change_requests');
        Schema::dropIfExists('amla_log_entries');
    }
};
