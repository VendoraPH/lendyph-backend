<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The year-end close (Phase 2, round C, slice C3): one row per close of a
 * year, and one entry per branch whose closing journal it posted.
 *
 * - `closed_year` equals `year` while the close stands and is null once it is
 *   reopened. Its UNIQUE index is what allows one live close per year: a close
 *   racing another fails on it (409), and a reopened row stays beside the
 *   later close of the same year.
 * - An entry is its closing journal's postable. JournalPoster allows one
 *   `closing` journal per postable, so each entry posts exactly one. Its
 *   `accounting_journal_id` is null only inside the close's transaction,
 *   between the entry's insert and the post of its journal.
 *
 * Two new tables: no existing row changes, so there is nothing to audit or
 * preview. Created only when missing, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_year_closes')) {
            Schema::create('accounting_year_closes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedSmallInteger('year');
                $table->enum('status', ['closed', 'reopened']);
                $table->unsignedSmallInteger('closed_year')->nullable()->unique();
                $table->foreignId('net_surplus_account_id')->constrained('accounting_accounts')->restrictOnDelete();
                // Centavos, signed: a profit is positive, a loss negative.
                $table->bigInteger('net_surplus');
                // The ids of the periods this close closed, in date order.
                $table->json('periods_closed');
                $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->dateTime('closed_at');
                $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->dateTime('reopened_at')->nullable();
                $table->text('reopen_reason')->nullable();
                $table->timestamps();

                $table->index(['year', 'id']);
            });
        }

        if (! Schema::hasTable('accounting_year_close_entries')) {
            Schema::create('accounting_year_close_entries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('accounting_year_close_id')->constrained('accounting_year_closes')->restrictOnDelete();
                // Null for the journals with no branch.
                $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
                $table->foreignId('accounting_journal_id')->nullable()->unique()->constrained('accounting_journals')->restrictOnDelete();
                // Centavos, signed, as on the close.
                $table->bigInteger('net_surplus');
                $table->timestamps();
            });
        }
    }

    /**
     * Refused once a close exists: its closing journals point at these rows,
     * and dropping them would leave posted entries with no record of the close
     * that wrote them.
     */
    public function down(): void
    {
        if (Schema::hasTable('accounting_year_closes') && DB::table('accounting_year_closes')->exists()) {
            throw new RuntimeException('Year-end closes exist. They and their closing journals stay on the record.');
        }

        Schema::dropIfExists('accounting_year_close_entries');
        Schema::dropIfExists('accounting_year_closes');
    }
};
