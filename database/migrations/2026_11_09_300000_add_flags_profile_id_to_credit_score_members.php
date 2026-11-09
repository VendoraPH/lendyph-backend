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
 *
 * A plain indexed id with no foreign key on purpose: a foreign key that set
 * it to null when a draft's details were deleted would read as "no
 * application details" and open the figures' gate. A dangling id finds no
 * loan, so the gate stays shut.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('credit_score_members', 'flags_profile_id')) {
            Schema::table('credit_score_members', function (Blueprint $table) {
                $table->unsignedBigInteger('flags_profile_id')->nullable()->after('previous_credit_score_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('credit_score_members', 'flags_profile_id')) {
            Schema::table('credit_score_members', function (Blueprint $table) {
                $table->dropIndex(['flags_profile_id']);
                $table->dropColumn('flags_profile_id');
            });
        }
    }
};
