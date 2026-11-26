<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A share capital auto-credit run is for one cycle (finish run round 8), as
 * the savings auto-credit picks its cycle: `cycle_date` is a month's 15th or
 * its 30th (the month's last day). New runs only: past runs keep null, and
 * their rows count toward the cycle their date falls in (1st–15th, or later).
 *
 * Only adds, skipped when already there, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('auto_credit_runs', 'cycle_date')) {
            Schema::table('auto_credit_runs', function (Blueprint $table): void {
                $table->date('cycle_date')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('auto_credit_runs', 'cycle_date')) {
            Schema::table('auto_credit_runs', function (Blueprint $table): void {
                $table->dropColumn('cycle_date');
            });
        }
    }
};
