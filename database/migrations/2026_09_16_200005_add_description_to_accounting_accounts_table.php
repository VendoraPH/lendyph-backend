<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `Account.description` — a field the contract has always had and the database
 * never did.
 *
 * `src/types/accounting.ts` declares `description?: string | null` on `Account`,
 * and both account FormRequests validate whitelists, so a payload carrying one
 * was accepted, dropped at mass assignment, and answered with a 200 that did
 * not contain it. Nothing failed; the note an administrator wrote explaining
 * what "1170 Other Receivables" is for simply did not exist on the next page
 * load.
 *
 * TEXT rather than a VARCHAR because it is prose — "Advances to field officers,
 * settled against payroll" — and the rules cap it at 500 characters at the
 * boundary where a person can be told about the limit.
 *
 * No TimezoneShift entry: this alters an existing table and adds no
 * datetime/timestamp column, so the shift's schema-drift test has nothing new
 * to account for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_accounts', function (Blueprint $table) {
            $table->text('description')->nullable()->after('cash_kind');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_accounts', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
