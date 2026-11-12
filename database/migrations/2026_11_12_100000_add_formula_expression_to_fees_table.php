<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Formula fee may be written as an expression (Phase 4):
 * `fees.formula_expression`, up to 255 characters, null for every existing fee
 * (today's "rate of principal" recipe, unchanged). No data changes, and it can
 * run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fees', 'formula_expression')) {
            return;
        }

        Schema::table('fees', function (Blueprint $table) {
            $table->string('formula_expression', 255)->nullable()->after('max_amount');
        });
    }

    /**
     * Refused while any fee is written as a formula: without its formula it
     * would fall back to a 0% recipe and silently stop charging.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('fees', 'formula_expression')) {
            return;
        }

        if (DB::table('fees')->whereNotNull('formula_expression')->exists()) {
            throw new RuntimeException('Fees written as a formula exist. Change or delete them in Settings before rolling this migration back.');
        }

        Schema::table('fees', function (Blueprint $table) {
            $table->dropColumn('formula_expression');
        });
    }
};
