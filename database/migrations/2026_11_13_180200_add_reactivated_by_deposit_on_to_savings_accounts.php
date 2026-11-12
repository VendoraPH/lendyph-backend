<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day money paid in (a deposit or a transfer in) last reactivated a
 * dormant account (gap round S4, review M1). On that day the first money
 * going out of it needs a checker (ApprovalGate::uncheckedOutflowReason()).
 * Empty on every existing account. A DATE, so TimezoneShift has nothing to
 * register. Added only when missing, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('savings_accounts', 'reactivated_by_deposit_on')) {
            return;
        }

        Schema::table('savings_accounts', function (Blueprint $table): void {
            $table->date('reactivated_by_deposit_on')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('savings_accounts', 'reactivated_by_deposit_on')) {
            return;
        }

        Schema::table('savings_accounts', function (Blueprint $table): void {
            $table->dropColumn('reactivated_by_deposit_on');
        });
    }
};
