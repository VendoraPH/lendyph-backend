<?php

use App\Services\Amla\Cases\SetAsideBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMLA reports and the B6 follow-ups (Phase 6, AMLA-B7):
 * - `amla_exports`: each report export the server made (PDF, XLSX or CSV),
 *   on the private disk, for the user who asked, downloaded only through a
 *   signed link re-checked against that user. Kept forever.
 * - `amla_ctr_set_asides`: each ask to set a CTR candidate aside, in its own
 *   row, with its reason and the decision (a refusal's reason too, both
 *   encrypted), so asking again never overwrites an earlier ask.
 *   `pending_key` (the candidate id while waiting) keeps one ask open per
 *   candidate. Asks already waiting are copied in by SetAsideBackfill
 *   (`amla:backfill-set-asides --dry-run` previews it).
 * - an index on `amla_events.created_at`, the detector's lower-bound hint.
 *
 * Nothing outside AMLA is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amla_exports', function (Blueprint $table) {
            $table->id();
            $table->string('report_key', 40);
            $table->string('format', 4);
            $table->json('filters');
            $table->string('title', 150);
            $table->boolean('is_draft');
            $table->string('file_path', 255);
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);
            $table->unsignedInteger('row_count');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'id']);
        });

        Schema::create('amla_ctr_set_asides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ctr_candidate_id')->constrained('amla_ctr_candidates')->restrictOnDelete();
            $table->text('reason');
            $table->string('status', 10)->default('pending');
            $table->unsignedBigInteger('pending_key')->nullable()->unique();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['ctr_candidate_id', 'id']);
        });

        Schema::table('amla_events', function (Blueprint $table) {
            $table->index('created_at');
        });

        app(SetAsideBackfill::class)->run(dryRun: false);
    }

    public function down(): void
    {
        if ((Schema::hasTable('amla_exports') && DB::table('amla_exports')->exists())
            || (Schema::hasTable('amla_ctr_set_asides') && DB::table('amla_ctr_set_asides')->exists())) {
            throw new RuntimeException('AMLA exports and set-aside asks are kept forever, so the AMLA report tables are not dropped.');
        }

        Schema::table('amla_events', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
        Schema::dropIfExists('amla_ctr_set_asides');
        Schema::dropIfExists('amla_exports');
    }
};
