<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fee type "formula" (decision 5e): a percentage of the principal (`value`),
 * optionally per month of the term (`per_month`), kept between `min_amount`
 * and `max_amount` (each optional, in pesos).
 *
 * `fees.type` is a MySQL ENUM, so the new value takes a raw MODIFY that lists
 * every value; existing rows keep theirs. No data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE fees MODIFY type ENUM('fixed','percentage','formula') NOT NULL");

        Schema::table('fees', function (Blueprint $table) {
            $table->boolean('per_month')->default(false)->after('value');
            $table->decimal('min_amount', 12, 2)->nullable()->after('per_month');
            $table->decimal('max_amount', 12, 2)->nullable()->after('min_amount');
        });
    }

    /**
     * Refused while any formula fee exists: turning one into another type
     * would silently change what every later release charges.
     */
    public function down(): void
    {
        if (DB::table('fees')->where('type', 'formula')->exists()) {
            throw new RuntimeException('Formula fees exist. Change or delete them in Settings before rolling this migration back.');
        }

        Schema::table('fees', function (Blueprint $table) {
            $table->dropColumn(['per_month', 'min_amount', 'max_amount']);
        });

        DB::statement("ALTER TABLE fees MODIFY type ENUM('fixed','percentage') NOT NULL");
    }
};
