<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optimistic concurrency for online applications (PR #332 review): `version`
 * moves on with every save, and ApplicationTransitions::move() refuses (409)
 * a move made on a version the client read before another change, even when
 * the status came back to what it was. New column only; running it again
 * changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('origination_applications', 'version')) {
            Schema::table('origination_applications', function (Blueprint $table): void {
                $table->unsignedInteger('version')->default(0)->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('origination_applications', 'version')) {
            Schema::table('origination_applications', function (Blueprint $table): void {
                $table->dropColumn('version');
            });
        }
    }
};
