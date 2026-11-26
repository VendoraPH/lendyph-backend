<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A share capital auto-credit run is for one half of the month (finish run
 * round 8), as the savings auto-credit picks its cycle: `cycle` is `15` (the
 * 15th) or `30` (the 30th). New runs only: past runs keep null, and their rows
 * count toward a half by their date (1st–15th, or later).
 *
 * Only adds, skipped when already there, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('auto_credit_runs', 'cycle')) {
            Schema::table('auto_credit_runs', function (Blueprint $table): void {
                $table->string('cycle', 2)->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('auto_credit_runs', 'cycle')) {
            Schema::table('auto_credit_runs', function (Blueprint $table): void {
                $table->dropColumn('cycle');
            });
        }
    }
};
