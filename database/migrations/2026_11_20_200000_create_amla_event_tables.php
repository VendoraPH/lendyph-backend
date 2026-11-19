<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMLA transaction events (Phase 6, AMLA-B2; AMLA design §4.2–4.5):
 * - `amla_events`: one row per customer money movement, keyed by (source,
 *   source id, kind). The amount is the source row's own, copied as stored,
 *   never worked out from a journal. Internal movements (transfers, interest,
 *   rollovers, a restructure's carried principal) are rows too, marked
 *   `is_internal`, and never count as new funds. A void or reversal marks the
 *   event once (`reversed_at`); nothing else on it ever changes.
 * - `amla_harvest_cursors`: one watermark per source, on the source's own
 *   recording time. `started_at` is go-live: nothing recorded before it is
 *   harvested except by `amla:screen-history`.
 * - `amla_event_failures`: a source row the harvester couldn't turn into an
 *   event, with why, kept as written. It reads as Unscreened until its event
 *   exists.
 * - `amla_coverage_checks`: the nightly check, per source and Manila day.
 *
 * Members, walk-ins and branches referenced here can't be deleted (restrict);
 * every source row already restricts them. No money table changes, and
 * nothing outside these tables is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amla_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('kind', 40);
            $table->string('direction', 3);
            $table->boolean('is_internal');
            $table->string('channel', 30)->nullable();
            $table->decimal('amount', 14, 2);
            $table->foreignId('borrower_id')->nullable()->constrained('borrowers')->restrictOnDelete();
            $table->foreignId('gcash_non_member_id')->nullable()->constrained('gcash_non_members')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->date('occurred_on');
            $table->timestamp('recorded_at')->nullable();
            $table->string('reference', 40)->nullable();
            $table->string('channel_reference', 100)->nullable();
            $table->string('linked_source', 32)->nullable();
            $table->unsignedBigInteger('linked_source_id')->nullable();
            $table->string('harvested_by', 10);
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_source', 32)->nullable();
            $table->unsignedBigInteger('reversal_source_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['source', 'source_id', 'kind']);
            $table->index(['borrower_id', 'occurred_on']);
            $table->index(['gcash_non_member_id', 'occurred_on']);
            $table->index(['branch_id', 'occurred_on']);
            $table->index(['occurred_on', 'id']);
            $table->index(['linked_source', 'linked_source_id']);
        });

        Schema::create('amla_harvest_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32)->unique();
            $table->timestamp('watermark')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
        });

        Schema::create('amla_event_failures', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('reason', 500);
            $table->string('harvested_by', 10);
            $table->timestamp('created_at')->nullable();

            $table->unique(['source', 'source_id']);
        });

        Schema::create('amla_coverage_checks', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('source', 32);
            $table->unsignedInteger('source_rows');
            $table->unsignedInteger('events');
            $table->unsignedInteger('failed');
            $table->unsignedInteger('unscreened');
            $table->unsignedInteger('pending');
            $table->timestamp('created_at')->nullable();

            $table->index(['day', 'source']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('amla_events') && DB::table('amla_events')->exists()) {
            throw new RuntimeException('AMLA events are kept forever, so the AMLA event tables are not dropped.');
        }

        Schema::dropIfExists('amla_coverage_checks');
        Schema::dropIfExists('amla_event_failures');
        Schema::dropIfExists('amla_harvest_cursors');
        Schema::dropIfExists('amla_events');
    }
};
