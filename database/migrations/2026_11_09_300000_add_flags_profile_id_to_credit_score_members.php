<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The application details a member's open flags were last raised from
 * (`credit_score_members.flags_profile_id`). A member's score row only
 * changes with the score, but the flags are rewritten on every run, so the
 * API checks a caller's access to the flags' figures against this, not the
 * score row's own details. Added empty: the next run fills it. Safe to run
 * twice; changes no existing value.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('credit_score_members', 'flags_profile_id')) {
            Schema::table('credit_score_members', function (Blueprint $table) {
                $table->foreignId('flags_profile_id')->nullable()->after('previous_credit_score_id')
                    ->constrained('credit_application_profiles')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('credit_score_members', 'flags_profile_id')) {
            Schema::table('credit_score_members', function (Blueprint $table) {
                $table->dropConstrainedForeignId('flags_profile_id');
            });
        }
    }
};
