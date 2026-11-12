<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account opening details (gap round S1, new records only): the account
 * officer, the source of funds (a key, plus the member's own words when it
 * is `other`) and an optional beneficiary {name, relationship,
 * contact_number}. Nullable, so existing accounts read "not recorded".
 *
 * Added only when missing, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('savings_accounts', 'account_officer_id')) {
            return;
        }

        Schema::table('savings_accounts', function (Blueprint $table): void {
            $table->foreignId('account_officer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('source_of_funds', 120)->nullable();
            $table->string('source_of_funds_other', 120)->nullable();
            $table->json('beneficiary')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('savings_accounts', 'account_officer_id')) {
            return;
        }

        Schema::table('savings_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('account_officer_id');
            $table->dropColumn(['source_of_funds', 'source_of_funds_other', 'beneficiary']);
        });
    }
};
