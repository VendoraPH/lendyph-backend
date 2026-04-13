<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds role metadata so the frontend Role Management page can persist
 * description/active/system flags and the seeded roles are protected
 * from rename/delete.
 */
return new class extends Migration
{
    /**
     * System roles that are seeded at install and must not be renamed or deleted.
     */
    private const SYSTEM_ROLE_NAMES = [
        'admin',
        'loan_officer',
        'cashier',
        'collector',
        'viewer',
        'general_bookkeeper',
    ];

    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->text('description')->nullable()->after('guard_name');
            $table->boolean('is_active')->default(true)->after('description');
            $table->boolean('is_system')->default(false)->after('is_active');
        });

        // Backfill: mark any already-seeded roles with known names as system
        DB::table('roles')
            ->whereIn('name', self::SYSTEM_ROLE_NAMES)
            ->update(['is_system' => true, 'is_active' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['description', 'is_active', 'is_system']);
        });
    }
};
