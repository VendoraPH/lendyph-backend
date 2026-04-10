<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add ongoing, completed, defaulted, restructured statuses.
     * Migrate existing 'closed' rows to 'completed' (semantically equivalent).
     */
    public function up(): void
    {
        // Step 1: temporarily expand enum to allow both old and new values
        DB::statement("ALTER TABLE loans MODIFY status ENUM(
            'draft','for_review','approved','rejected',
            'released','ongoing','completed','defaulted','restructured',
            'closed','void'
        ) NOT NULL DEFAULT 'draft'");

        // Step 2: migrate existing 'closed' rows → 'completed'
        DB::table('loans')->where('status', 'closed')->update(['status' => 'completed']);

        // Step 3: drop 'closed' from enum (frontend type still allows it but backend won't produce it)
        DB::statement("ALTER TABLE loans MODIFY status ENUM(
            'draft','for_review','approved','rejected',
            'released','ongoing','completed','defaulted','restructured','void'
        ) NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        // Re-add 'closed' temporarily
        DB::statement("ALTER TABLE loans MODIFY status ENUM(
            'draft','for_review','approved','rejected',
            'released','ongoing','completed','defaulted','restructured',
            'closed','void'
        ) NOT NULL DEFAULT 'draft'");

        // Revert: completed → closed; ongoing → released; defaulted/restructured → released
        DB::table('loans')->where('status', 'completed')->update(['status' => 'closed']);
        DB::table('loans')->where('status', 'ongoing')->update(['status' => 'released']);
        DB::table('loans')->whereIn('status', ['defaulted', 'restructured'])->update(['status' => 'released']);

        // Drop the new statuses
        DB::statement("ALTER TABLE loans MODIFY status ENUM(
            'draft','for_review','approved','rejected','released','closed','void'
        ) NOT NULL DEFAULT 'draft'");
    }
};
