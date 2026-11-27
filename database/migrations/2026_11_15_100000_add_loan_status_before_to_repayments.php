<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The loan's status when each payment was recorded (round Y, recovery
 * payments on defaulted loans), so a void puts back exactly the status the
 * loan had: the payment that completed a loan which was defaulted at the
 * time returns it to Defaulted, not Ongoing. Credit Scoring reads it too: a
 * loan completed by a payment made while it was defaulted ended badly.
 *
 * New payments only. Older rows stay empty and keep today's void rule; none
 * of them could be made on a defaulted loan, so nothing is lost. A string, not
 * an ENUM, so a new loan status needs no DDL here; not a datetime, so
 * TimezoneShift has nothing to register. Runs safely twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('repayments', 'loan_status_before')) {
            return;
        }

        Schema::table('repayments', function (Blueprint $table): void {
            $table->string('loan_status_before', 32)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('repayments', 'loan_status_before')) {
            return;
        }

        Schema::table('repayments', function (Blueprint $table): void {
            $table->dropColumn('loan_status_before');
        });
    }
};
