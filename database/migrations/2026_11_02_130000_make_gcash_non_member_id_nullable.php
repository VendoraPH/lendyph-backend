<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An ID document is optional for a GCash walk-in (Kevin's request, approved by
 * Arvin on 2026-11-02): the counter serves customers who carry no ID. The two
 * columns are both set or both null; StoreGCashNonMemberRequest enforces that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gcash_non_members', function (Blueprint $table) {
            $table->string('id_type', 64)->nullable()->change();
            $table->string('id_number', 64)->nullable()->change();
        });
    }

    /**
     * Refuses to roll back while any walk-in, removed ones included, has no ID.
     * Making the columns NOT NULL again would need a value for those rows, and
     * inventing an ID document for a real customer is worse than a failed
     * rollback. Give those walk-ins an ID (or decide what to do with them)
     * first, then roll back.
     */
    public function down(): void
    {
        $withoutId = DB::table('gcash_non_members')
            ->where(fn ($q) => $q->whereNull('id_type')->orWhereNull('id_number'))
            ->count();

        if ($withoutId > 0) {
            throw new RuntimeException(
                "Cannot make the walk-in ID columns required again: {$withoutId} walk-in(s) have no ID. Give them an ID first.",
            );
        }

        Schema::table('gcash_non_members', function (Blueprint $table) {
            $table->string('id_type', 64)->nullable(false)->change();
            $table->string('id_number', 64)->nullable(false)->change();
        });
    }
};
