<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMLA cases and filings (Phase 6, AMLA-B6; AMLA design §4.13–4.16; task
 * §12–15):
 * - alerts gain their workflow: an assignee, the case they were escalated
 *   to, and when they were closed; `amla_alert_actions` is each step, kept
 *   as written, with any note encrypted;
 * - `amla_cases`, their alerts, notes (encrypted), evidence (on the private
 *   disk; names and descriptions encrypted) and `amla_case_actions`, the
 *   case's own history;
 * - CTR candidates gain their filing status and a not-reportable reason
 *   (encrypted);
 * - `amla_report_formats` (version 1 is an internal record filed by hand on
 *   the AMLC portal: the GoTRACS file adapter isn't built),
 *   `amla_reports` (CTR and STR records: fields and narrative encrypted)
 *   and `amla_report_submissions` (each manual filing step with its
 *   reference, date and evidence);
 * - `amla_inbox_items`: AMLA's own inbox, read only in AMLA screens, naming
 *   a reference and never a person;
 * - `amla_risk_downgrades`: overrides of a High risk down, waiting for a
 *   second CDD holder.
 *
 * Every row is kept forever. Members, walk-ins, branches and users referenced
 * here can't be deleted (restrict). No money table changes, and nothing
 * outside AMLA is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amla_cases', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->string('status', 30)->default('open');
            $table->text('title');
            $table->foreignId('borrower_id')->nullable()->constrained('borrowers')->restrictOnDelete();
            $table->foreignId('gcash_non_member_id')->nullable()->constrained('gcash_non_members')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decision', 10)->nullable();
            $table->text('decision_reason')->nullable();
            $table->date('determination_date')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });

        Schema::table('amla_alerts', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->after('status')->constrained('users')->restrictOnDelete();
            $table->foreignId('case_id')->nullable()->after('assigned_to')->constrained('amla_cases')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable()->after('cleared_note');
        });

        Schema::create('amla_alert_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('amla_alerts')->restrictOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('amla_cases')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['alert_id', 'id']);
        });

        Schema::create('amla_case_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('amla_cases')->restrictOnDelete();
            $table->foreignId('alert_id')->unique()->constrained('amla_alerts')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('amla_case_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('amla_cases')->restrictOnDelete();
            $table->text('body');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['case_id', 'id']);
        });

        Schema::create('amla_case_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('amla_cases')->restrictOnDelete();
            $table->string('file_path', 255);
            $table->text('original_name');
            $table->text('description')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['case_id', 'id']);
        });

        Schema::create('amla_case_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('amla_cases')->restrictOnDelete();
            $table->string('action', 30);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['case_id', 'id']);
        });

        Schema::table('amla_ctr_candidates', function (Blueprint $table) {
            $table->text('not_reportable_reason')->nullable()->after('status');
            $table->foreignId('status_changed_by')->nullable()->after('not_reportable_reason')->constrained('users')->restrictOnDelete();
        });

        Schema::create('amla_report_formats', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->boolean('file_available')->default(false);
            $table->boolean('to_verify')->default(true);
            $table->text('notes');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('amla_reports', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->string('type', 3);
            $table->string('status', 30);
            $table->foreignId('format_id')->constrained('amla_report_formats')->restrictOnDelete();
            $table->foreignId('ctr_candidate_id')->nullable()->constrained('amla_ctr_candidates')->restrictOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('amla_cases')->restrictOnDelete();
            $table->foreignId('borrower_id')->nullable()->constrained('borrowers')->restrictOnDelete();
            $table->foreignId('gcash_non_member_id')->nullable()->constrained('gcash_non_members')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->text('fields');
            $table->text('narrative')->nullable();
            $table->date('deadline_from');
            $table->unsignedInteger('deadline_settings_version');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('returned_reason')->nullable();
            $table->string('submission_reference', 100)->nullable();
            $table->date('submitted_on')->nullable();
            $table->timestamps();

            $table->index(['type', 'status', 'id']);
            $table->index(['branch_id', 'type']);
        });

        Schema::create('amla_report_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('amla_reports')->restrictOnDelete();
            $table->string('status', 30);
            $table->string('reference', 100);
            $table->date('occurred_on');
            $table->text('note')->nullable();
            $table->string('evidence_path', 255);
            $table->text('evidence_name');
            $table->string('evidence_mime', 100)->nullable();
            $table->unsignedBigInteger('evidence_size');
            $table->char('evidence_sha256', 64);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['report_id', 'id']);
        });

        Schema::create('amla_inbox_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 40);
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id');
            $table->string('message', 255);
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'read_at', 'id']);
        });

        Schema::create('amla_risk_downgrades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('amla_profiles')->restrictOnDelete();
            $table->string('from_level', 10);
            $table->string('to_level', 10);
            $table->text('reason');
            $table->string('status', 10)->default('pending');
            $table->unsignedBigInteger('pending_key')->nullable()->unique();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('assessment_id')->nullable()->constrained('amla_risk_assessments')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'id']);
        });

        $now = now();

        DB::table('amla_report_formats')->insert([
            'code' => 'internal_v1',
            'name' => 'Internal record, filed by hand on the AMLC portal',
            'file_available' => false,
            'to_verify' => true,
            'notes' => 'The GoTRACS electronic file waits for the verified AMLC specification. Until then each report is recorded here and filed by hand on the AMLC portal; an export is an internal review copy, never the prescribed file.',
            'created_at' => $now,
        ]);

        DB::table('amla_detection_locks')->insert([
            ['name' => 'cases', 'created_at' => $now],
            ['name' => 'reports', 'created_at' => $now],
        ]);
    }

    public function down(): void
    {
        if ((Schema::hasTable('amla_cases') && DB::table('amla_cases')->exists())
            || (Schema::hasTable('amla_reports') && DB::table('amla_reports')->exists())
            || (Schema::hasTable('amla_alert_actions') && DB::table('amla_alert_actions')->exists())) {
            throw new RuntimeException('AMLA cases, reports and alert decisions are kept forever, so the AMLA case tables are not dropped.');
        }

        DB::table('amla_detection_locks')->whereIn('name', ['cases', 'reports'])->delete();

        Schema::dropIfExists('amla_risk_downgrades');
        Schema::dropIfExists('amla_inbox_items');
        Schema::dropIfExists('amla_report_submissions');
        Schema::dropIfExists('amla_reports');
        Schema::dropIfExists('amla_report_formats');

        Schema::table('amla_ctr_candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_changed_by');
            $table->dropColumn('not_reportable_reason');
        });

        Schema::dropIfExists('amla_case_actions');
        Schema::dropIfExists('amla_case_evidence');
        Schema::dropIfExists('amla_case_notes');
        Schema::dropIfExists('amla_case_alerts');
        Schema::dropIfExists('amla_alert_actions');

        Schema::table('amla_alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('case_id');
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn('closed_at');
        });

        Schema::dropIfExists('amla_cases');
    }
};
