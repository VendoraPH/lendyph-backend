<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two additions for the CIC records slice:
 *
 * - `cic_captures.corrects_capture_id`: a correction capture names the
 *   complete month-end capture it corrects. Its contract records are copied
 *   from that capture, and its subject records are rebuilt from today's
 *   member data (prompt §14).
 * - `cic_capture_records.build_issues`: what the record builder found that
 *   the values alone can't show (an unmapped product, a seventh co-maker, a
 *   contract number too long to freeze), frozen with the record so a
 *   capture's issues never depend on today's data. Messages are in words and
 *   hold no member values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cic_captures', function (Blueprint $table) {
            $table->foreignId('corrects_capture_id')->nullable()->after('kind')->constrained('cic_captures')->restrictOnDelete();
        });

        Schema::table('cic_capture_records', function (Blueprint $table) {
            $table->json('build_issues')->nullable()->after('values');
        });
    }

    public function down(): void
    {
        Schema::table('cic_capture_records', function (Blueprint $table) {
            $table->dropColumn('build_issues');
        });

        Schema::table('cic_captures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corrects_capture_id');
        });
    }
};
