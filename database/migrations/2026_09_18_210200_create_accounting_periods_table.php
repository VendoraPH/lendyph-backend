<?php

use App\Services\Accounting\PeriodGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The months the books are kept in, and whether each one still accepts entries.
 *
 * ## Closing has to LOCK, or it is only a label
 *
 * A closed period that still accepts postings is not closed. So this table is
 * read by {@see PeriodGuard} on every path that
 * writes a journal — drafting, posting, reversing, and every automatic entry
 * the lending engine raises — and a `closed` row refuses them.
 *
 * ## An absent period does NOT lock
 *
 * A date with no row here posts freely. That is deliberate and it is the only
 * safe default: periods are provisioned by the Period Closing screen, and a
 * co-op that has never opened it would otherwise find its own books refusing
 * every entry on the day this shipped. Locking is something someone turns on,
 * one month at a time, on purpose.
 *
 * ## Reopening is recorded
 *
 * The confirmation dialog on that screen promises it in as many words. The
 * Auditable trait on the model writes the audit-log entry, and the two columns
 * below keep the answer on the period row itself — so "was this month ever
 * reopened after it was signed off?" is a question about the period rather than
 * a search through a log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();

            // "2026-09". Fixed-width, so lexical order is chronological order —
            // the same reasoning as account codes, and what lets the list be
            // ordered without parsing anything.
            $table->string('code', 16)->unique();

            // "September 2026". The label the screen shows and the toast reads
            // back ("September 2026 closed.").
            $table->string('name', 48);

            // Calendar dates. The period is the inclusive range between them,
            // and `PeriodGuard` asks which row a journal's `date` falls in.
            $table->date('start_date');
            $table->date('end_date');

            $table->enum('status', ['open', 'closed'])->default('open');

            // nullOnDelete: losing the user must not reopen the period.
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            // A real instant, unlike the dates above: the moment someone signed
            // the month off. Registered in TimezoneShift::EXCLUDED_COLUMNS —
            // this table postdates the UTC → Manila cutover, so it cannot hold
            // a row written by the old application and shifting it would move
            // correct Manila timestamps eight hours into the past.
            $table->dateTime('closed_at')->nullable();

            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reopened_at')->nullable();

            $table->timestamps();

            // "Which period does this date fall in?" — asked on every journal
            // write, so it gets its own index rather than a scan.
            $table->index(['start_date', 'end_date']);

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
