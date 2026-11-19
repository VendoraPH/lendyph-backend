<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 MB-BE1 (multi-branch decisions 2 and 4): a branch's profile,
     * its status and the main-office flag.
     *
     * - `status` is active, suspended or closed. Today's inactive branches
     *   become suspended.
     * - `is_active` stays for today's readers (the pickers, the public list,
     *   CDA counts and DefaultBranchAssignment), but is now generated from the
     *   status, so the two can never disagree, whoever writes the row.
     * - `is_main_office` marks the main office. `main_office_key` is 1 on the
     *   flagged row and null elsewhere, and its unique index holds at most one
     *   main office. The flag is set by data fix 1
     *   (2026_11_19_310200_mark_the_main_office).
     * - The CDA authority fields are "to verify" until counsel confirms which
     *   details a branch must keep (multi-branch §7 Q4).
     * - The opening, closing and CDA dates are DATE columns, so nothing is
     *   added to TimezoneShift.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('status', 16)->default('active')->after('is_active');
            $table->boolean('is_main_office')->default(false)->after('status');
            $table->string('email')->nullable()->after('contact_number');
            $table->foreignId('manager_user_id')->nullable()->after('email')->constrained('users')->nullOnDelete();
            $table->date('opened_on')->nullable()->after('manager_user_id');
            $table->date('closed_on')->nullable()->after('opened_on');
            $table->string('cda_authority_number', 100)->nullable()->after('closed_on');
            $table->date('cda_authority_issued_on')->nullable()->after('cda_authority_number');
        });

        DB::table('branches')->where('is_active', false)->update(['status' => 'suspended']);

        DB::statement("ALTER TABLE branches MODIFY is_active TINYINT(1) GENERATED ALWAYS AS (`status` = 'active') STORED NOT NULL");

        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedTinyInteger('main_office_key')->nullable()->storedAs('if(`is_main_office`, 1, null)')->after('is_main_office');
            $table->unique('main_office_key', 'branches_one_main_office_unique');
            $table->index('status');
        });
    }

    /**
     * Back to a plain `is_active` holding what the status gave it (suspended
     * and closed both read inactive), without the profile.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_one_main_office_unique');
            $table->dropIndex(['status']);
            $table->dropColumn('main_office_key');
        });

        DB::statement('ALTER TABLE branches MODIFY is_active TINYINT(1) NOT NULL DEFAULT 1');

        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_user_id');
            $table->dropColumn(['status', 'is_main_office', 'email', 'opened_on', 'closed_on', 'cda_authority_number', 'cda_authority_issued_on']);
        });
    }
};
