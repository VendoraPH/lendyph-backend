<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a loan's schedule rounds each period's interest (follow-ups batch,
 * decision 1): `legacy` (the float factor every loan was charged with until
 * now) or `exact` (LoanTermSchedule::interestFor(), stamped at release).
 *
 * Every loan already released, or holding a saved schedule (an imported
 * loan, a restructured or completed one), becomes `legacy`, so a Ledger
 * rebuild, contract total, statement or extension of it gives the figures it
 * always did. A loan not yet released stays empty and is worked out exactly.
 * Nothing else is written, and no figure changes.
 *
 * Runs safely twice: the column is added only when missing, and the marking
 * runs only while no loan has been released since the change (no `exact`
 * marker yet), so a second run never marks a newer loan `legacy`. A string,
 * not an ENUM, so a later rounding needs no DDL; not a datetime, so
 * TimezoneShift has nothing to register.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('loans', 'schedule_rounding')) {
            Schema::table('loans', function (Blueprint $table): void {
                $table->string('schedule_rounding', 16)->nullable();
            });
        }

        if (DB::table('loans')->where('schedule_rounding', 'exact')->exists()) {
            return;
        }

        DB::table('loans')
            ->whereNull('schedule_rounding')
            ->where(fn ($query) => $query
                ->whereNotNull('released_at')
                ->orWhereExists(fn ($schedules) => $schedules
                    ->selectRaw('1')
                    ->from('amortization_schedules')
                    ->whereColumn('amortization_schedules.loan_id', 'loans.id')))
            ->update(['schedule_rounding' => 'legacy']);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('loans', 'schedule_rounding')) {
            return;
        }

        Schema::table('loans', function (Blueprint $table): void {
            $table->dropColumn('schedule_rounding');
        });
    }
};
