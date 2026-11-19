<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMLA detection (Phase 6, AMLA-B3; AMLA design §4.6–4.7):
 * - `amla_rule_versions`: immutable detection rule versions. Version 1 holds
 *   the documented starting points, "to verify", never legal thresholds. A
 *   change is an approved `detection_rules` change request and adds a version.
 * - `amla_ctr_candidates`: one per event that is a covered transaction
 *   candidate under the settings version in force (one cash transaction above
 *   the CTR threshold). A later void or reversal of its event is marked once.
 * - `amla_alerts`: one per rule, subject and window while open
 *   (`open_key`), citing the rule version it ran under, with its reason and
 *   risk indicators as detected. A rerun that finds it no longer holds clears
 *   it once, with a note.
 * - `amla_alert_events`: the transactions behind each alert; each records
 *   once that its event's reversal was rerun.
 * - `amla_event_screenings`: one row per event the detector has screened,
 *   written in the same transaction as that event's CTR candidate and alerts,
 *   naming the rule and settings versions it was judged by. An event with no
 *   row is unscreened, whatever its id, so an event saved late below others is
 *   still screened. Kept as written.
 * - `amla_detection_locks`: the row the detector locks, so two runs never
 *   write at once. Written here, so the detector only ever locks it and
 *   never reads it before the lock.
 *
 * Members, walk-ins, branches, events and users referenced here can't be
 * deleted (restrict). No money table changes, and nothing outside these
 * tables is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amla_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            // Text, not JSON: MySQL's JSON type reorders keys, and the rules read in their written order.
            $table->text('definition');
            $table->boolean('to_verify')->default(true);
            $table->timestamp('effective_at');
            $table->foreignId('request_id')->nullable()->constrained('amla_change_requests')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('amla_ctr_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('event_id')->unique()->constrained('amla_events')->restrictOnDelete();
            $table->unsignedInteger('settings_version');
            $table->decimal('threshold', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->string('direction', 3);
            $table->string('channel', 30)->nullable();
            $table->foreignId('borrower_id')->nullable()->constrained('borrowers')->restrictOnDelete();
            $table->foreignId('gcash_non_member_id')->nullable()->constrained('gcash_non_members')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->date('occurred_on');
            $table->string('status', 30)->default('detected');
            $table->boolean('from_history')->default(false);
            $table->text('explanation');
            $table->timestamp('event_reversed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['branch_id', 'occurred_on']);
            $table->index(['occurred_on', 'id']);
        });

        Schema::create('amla_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->char('rule_code', 1);
            $table->unsignedInteger('rule_version');
            $table->string('priority', 10);
            $table->string('status', 20)->default('open');
            $table->string('open_key', 191)->nullable()->unique();
            $table->foreignId('borrower_id')->nullable()->constrained('borrowers')->restrictOnDelete();
            $table->foreignId('gcash_non_member_id')->nullable()->constrained('gcash_non_members')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->date('window_start');
            $table->date('window_end');
            $table->text('reason');
            // Text, not JSON, so the indicators keep their written order.
            $table->text('indicators');
            $table->boolean('from_history')->default(false);
            $table->timestamp('cleared_at')->nullable();
            $table->string('cleared_note', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['rule_code', 'status', 'id']);
            $table->index(['branch_id', 'status', 'id']);
            $table->index(['borrower_id', 'id']);
            $table->index(['gcash_non_member_id', 'id']);
        });

        Schema::create('amla_alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('amla_alerts')->restrictOnDelete();
            $table->foreignId('event_id')->constrained('amla_events')->restrictOnDelete();
            $table->timestamp('reversal_rerun_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['alert_id', 'event_id']);
            $table->index(['event_id', 'reversal_rerun_at']);
        });

        Schema::create('amla_event_screenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained('amla_events')->restrictOnDelete();
            $table->unsignedInteger('rule_version');
            $table->unsignedInteger('settings_version');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('amla_detection_locks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();
            $table->timestamp('created_at')->nullable();
        });

        $now = now();

        DB::table('amla_detection_locks')->insert(['name' => 'detector', 'created_at' => $now]);

        DB::table('amla_rule_versions')->insert([
            'version' => 1,
            'definition' => json_encode([
                'A' => ['active' => true, 'priority' => 'high', 'params' => ['min_transactions' => 2]],
                'B' => ['active' => true, 'priority' => 'medium', 'params' => ['min_transactions' => 10, 'multiplier' => 3, 'baseline_days' => 90]],
                'C' => ['active' => true, 'priority' => 'medium', 'params' => ['min_amount' => '50000.00', 'multiplier' => 5, 'baseline_days' => 90, 'min_baseline_transactions' => 3]],
                'D' => ['active' => true, 'priority' => 'medium', 'params' => ['min_amount' => '100000.00', 'multiplier' => 3]],
                'E' => ['active' => true, 'priority' => 'high', 'params' => ['min_in_amount' => '100000.00', 'out_share_pct' => 80, 'within_days' => 2]],
                'F' => ['active' => true, 'priority' => 'medium', 'params' => ['min_deposits' => 3, 'min_withdrawals' => 3, 'min_total' => '50000.00']],
                'G' => ['active' => false, 'priority' => 'low', 'params' => []],
                'H' => ['active' => true, 'priority' => 'medium', 'params' => ['min_transactions' => 5, 'min_total' => '100000.00']],
                'I' => ['active' => true, 'priority' => 'low', 'params' => ['min_amount' => '100000.00', 'min_share_pct' => 50, 'within_days' => 30, 'channels' => ['cash']]],
                'J' => ['active' => true, 'priority' => 'medium', 'params' => ['min_branches' => 3]],
                'K' => ['active' => true, 'priority' => 'medium', 'params' => ['review_amount' => '50000.00', 'channels' => ['cash']]],
                'L' => ['active' => true, 'priority' => 'critical', 'params' => []],
            ], JSON_THROW_ON_ERROR),
            'to_verify' => true,
            'effective_at' => $now,
            'created_at' => $now,
        ]);
    }

    public function down(): void
    {
        if ((Schema::hasTable('amla_alerts') && DB::table('amla_alerts')->exists())
            || (Schema::hasTable('amla_ctr_candidates') && DB::table('amla_ctr_candidates')->exists())) {
            throw new RuntimeException('AMLA alerts and CTR candidates are kept forever, so the AMLA detection tables are not dropped.');
        }

        Schema::dropIfExists('amla_detection_locks');
        Schema::dropIfExists('amla_event_screenings');
        Schema::dropIfExists('amla_alert_events');
        Schema::dropIfExists('amla_alerts');
        Schema::dropIfExists('amla_ctr_candidates');
        Schema::dropIfExists('amla_rule_versions');
    }
};
