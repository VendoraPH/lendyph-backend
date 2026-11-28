<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `cic_captures.left_out`: the loans a capture left out of its file and why,
 * as issues in words (record type, key, field, severity, code, message),
 * frozen with the capture so a left-out loan is never dropped silently: a
 * loan released and closed on the same day, or a completed loan with no
 * recorded closing payment or adjustment. Messages hold no member values.
 * New table, no rows yet: nothing to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cic_captures', function (Blueprint $table) {
            $table->json('left_out')->nullable()->after('event_count');
        });
    }

    public function down(): void
    {
        Schema::table('cic_captures', function (Blueprint $table) {
            $table->dropColumn('left_out');
        });
    }
};
