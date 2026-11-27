<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hold on money going out of an account that money paid in (a deposit
 * or a transfer in) reactivated (gap round S4, review M1): the day it was
 * set, until a checker approves an outflow on the account
 * (ApprovalGate::uncheckedOutflowReason()). Empty on every existing account.
 * A DATE, so TimezoneShift has nothing to register. Added only when missing,
 * so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('savings_accounts', 'outflow_check_required_since')) {
            return;
        }

        Schema::table('savings_accounts', function (Blueprint $table): void {
            $table->date('outflow_check_required_since')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('savings_accounts', 'outflow_check_required_since')) {
            return;
        }

        Schema::table('savings_accounts', function (Blueprint $table): void {
            $table->dropColumn('outflow_check_required_since');
        });
    }
};
