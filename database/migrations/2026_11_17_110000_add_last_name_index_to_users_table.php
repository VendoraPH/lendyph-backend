<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linda round 2, the full-name check (D2): before any model call, staff
 * users are read by last name (LindaNameCheck). borrowers.last_name already
 * has its index; users.last_name gets one, so the check stays one indexed
 * read per table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->index('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['last_name']);
        });
    }
};
