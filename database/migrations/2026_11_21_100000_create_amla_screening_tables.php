<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMLA screening, orders and savings holds (Phase 6, AMLA-B5; AMLA design §3.5,
 * §4.8, §4.11, decisions 5, 8, 11 and 12):
 * - `amla_watchlist_versions` and `amla_watchlist_entries`: each uploaded list
 *   (UN consolidated list, AMLC designations, an internal list) as a version
 *   and its entries, kept forever. A new version supersedes the last and
 *   waits to be rescreened (`rescreen_status`); a rescreen that runs out of
 *   time saves where it stopped (`rescreen_table`, `rescreen_after_id`).
 * - `amla_screening_matches`: potential matches with their score, threshold
 *   and reasons, reviewed by a person (confirmed or cleared, with an
 *   encrypted reason). `match_key` keeps one match per subject and listing,
 *   so a rescreen never repeats an unchanged listing.
 * - `amla_screening_cursors`: how far the every-minute screening of new or
 *   changed members, walk-ins and co-makers has read.
 * - `amla_orders` and `amla_order_holds`: the freeze-order register and the
 *   savings holds each order placed. Those holds never expire; only the
 *   order's release lifts them.
 * - `amla_order_release_requests`: a release takes two people. One
 *   `amla:sanctions` holder asks, with the reason and the lifting order's
 *   reference; a different one approves (or rejects). `pending_key` (the
 *   order id while pending) keeps one waiting request per order.
 *
 * No money table changes: savings holds are written through the savings code,
 * as legal holds. Users, members, accounts and holds referenced here can't be
 * deleted (restrict).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amla_watchlist_versions', function (Blueprint $table) {
            $table->id();
            $table->string('source', 10);
            $table->string('version_label', 150);
            $table->char('file_sha256', 64);
            $table->unsignedInteger('entry_count');
            $table->string('status', 12)->default('active');
            $table->timestamp('superseded_at')->nullable();
            $table->string('rescreen_status', 10)->default('pending');
            $table->string('rescreen_table', 30)->nullable();
            $table->unsignedBigInteger('rescreen_after_id')->nullable();
            $table->timestamp('rescreened_at')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['source', 'status']);
            $table->index('rescreen_status');
        });

        Schema::create('amla_watchlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('amla_watchlist_versions')->restrictOnDelete();
            $table->string('reference', 100);
            $table->string('kind', 12);
            $table->string('full_name', 255);
            $table->json('aliases');
            $table->json('birth_years');
            $table->json('nationalities');
            $table->char('fingerprint', 64);
            $table->timestamp('created_at')->nullable();

            $table->index(['version_id', 'id']);
            $table->index('fingerprint');
        });

        Schema::create('amla_screening_matches', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 10);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('entry_id')->constrained('amla_watchlist_entries')->restrictOnDelete();
            $table->foreignId('version_id')->constrained('amla_watchlist_versions')->restrictOnDelete();
            $table->string('source', 10);
            $table->string('match_key', 120)->unique();
            $table->unsignedTinyInteger('score');
            $table->unsignedTinyInteger('threshold');
            $table->json('reasons');
            $table->string('status', 10)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('amla_screening_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('subject_table', 30)->unique();
            $table->timestamp('watermark');
            $table->timestamps();
        });

        Schema::create('amla_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 150);
            $table->string('authority', 10);
            $table->date('received_on');
            $table->foreignId('borrower_id')->constrained('borrowers')->restrictOnDelete();
            $table->text('required_action');
            $table->text('details')->nullable();
            $table->foreignId('responsible_officer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('return_due_on')->nullable();
            $table->string('status', 10)->default('active');
            $table->foreignId('placed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('release_requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->string('release_reference', 150)->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });

        Schema::create('amla_order_release_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('amla_orders')->restrictOnDelete();
            $table->string('status', 10)->default('pending');
            $table->unsignedBigInteger('pending_key')->nullable()->unique();
            $table->text('reason');
            $table->string('release_reference', 150);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'id']);
        });

        Schema::create('amla_order_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('amla_orders')->restrictOnDelete();
            $table->foreignId('savings_hold_id')->unique()->constrained('savings_holds')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('savings_accounts')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * AMLA records are kept forever: once a list or an order exists, this
     * refuses rather than drop it.
     */
    public function down(): void
    {
        if ((Schema::hasTable('amla_watchlist_versions') && DB::table('amla_watchlist_versions')->exists())
            || (Schema::hasTable('amla_orders') && DB::table('amla_orders')->exists())) {
            throw new RuntimeException('AMLA watchlists and orders are kept forever, so the AMLA screening tables are not dropped.');
        }

        Schema::dropIfExists('amla_order_holds');
        Schema::dropIfExists('amla_order_release_requests');
        Schema::dropIfExists('amla_orders');
        Schema::dropIfExists('amla_screening_cursors');
        Schema::dropIfExists('amla_screening_matches');
        Schema::dropIfExists('amla_watchlist_entries');
        Schema::dropIfExists('amla_watchlist_versions');
    }
};
