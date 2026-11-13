<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The penalty a period held just before a payment reached it, on a defaulted
 * loan (round Y): `penalty_amount` and `penalty_months` as they stood before
 * the payment's own penalty step. A backdated recovery payment reads each
 * period's penalty as of its own date, which can be less than was charged
 * before the default; a void puts back exactly what the period held, so no
 * charge is lost.
 *
 * New payments on defaulted loans only; every other row stays empty and its
 * void works as before. Not datetimes, so TimezoneShift has nothing to
 * register. Runs safely twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('repayment_allocations', 'penalty_amount_before')) {
            return;
        }

        Schema::table('repayment_allocations', function (Blueprint $table): void {
            $table->decimal('penalty_amount_before', 12, 2)->nullable()->after('principal');
            $table->unsignedSmallInteger('penalty_months_before')->nullable()->after('penalty_amount_before');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('repayment_allocations', 'penalty_amount_before')) {
            return;
        }

        Schema::table('repayment_allocations', function (Blueprint $table): void {
            $table->dropColumn(['penalty_amount_before', 'penalty_months_before']);
        });
    }
};
