<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finish run round 4: the date order the admin settled on the Check step, kept
 * on the import run.
 *
 * `csv_import_runs.date_orders` holds, per file and per date column, `dmy`
 * (day-first) or `mdy` (month-first), or null where the column needed no
 * order. The server reads those columns with it when it stages the file, so
 * `03/04/2025` in a day-first column is 3 April, not 4 March.
 *
 * New imports only. A run that started before this has no orders (null), so a
 * file it stages reads as before, and rows already staged keep the dates they
 * were read as: the import pass writes from the staged values and never reads
 * a date cell again. Adds a nullable column and writes nothing, so running it
 * again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('csv_import_runs', 'date_orders')) {
            return;
        }

        Schema::table('csv_import_runs', function (Blueprint $table) {
            $table->json('date_orders')->nullable()->after('product_mapping');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('csv_import_runs', 'date_orders')) {
            return;
        }

        Schema::table('csv_import_runs', function (Blueprint $table) {
            $table->dropColumn('date_orders');
        });
    }
};
