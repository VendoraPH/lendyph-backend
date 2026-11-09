<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a credit policy flag was closed (`credit_policy_flags.cleared_reason`),
 * when it wasn't simply the condition no longer holding. Deleting a draft
 * closes its flags with `loan_deleted` (PolicyFlags::closeForDeletedLoan()),
 * since the delete nulls their loan and they would otherwise read as the
 * member's own; "New hard flags in 30 days" leaves them out (Lead's call 8).
 *
 * Added empty: flags closed before it keep no reason, and nothing is
 * backfilled. Safe to run twice; changes no existing value.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('credit_policy_flags', 'cleared_reason')) {
            Schema::table('credit_policy_flags', function (Blueprint $table) {
                $table->string('cleared_reason', 30)->nullable()->after('cleared_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('credit_policy_flags', 'cleared_reason')) {
            Schema::table('credit_policy_flags', function (Blueprint $table) {
                $table->dropColumn('cleared_reason');
            });
        }
    }
};
