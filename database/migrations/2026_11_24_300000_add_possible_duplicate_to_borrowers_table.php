<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The record a public registration may duplicate (finish run round 2,
     * decision 3).
     *
     * The public form is never refused as a duplicate any more: refusing let
     * anyone confirm that a person is on file and probe their birthdate. The
     * registration is saved pending instead, and the server links it to the
     * record the duplicate check matched, with the tier that matched it
     * (`same_name` or `similar_name_same_birthdate`), for the reviewer.
     *
     * Nullable and additive, with no backfill: no registration saved before
     * this was a duplicate, since the form refused them. Purging the matched
     * record clears the link (nullOnDelete) rather than blocking the purge.
     */
    public function up(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->foreignId('possible_duplicate_of_id')->nullable()->after('rejection_reason')
                ->constrained('borrowers')->nullOnDelete();
            $table->string('possible_duplicate_reason', 40)->nullable()->after('possible_duplicate_of_id');
        });
    }

    public function down(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('possible_duplicate_of_id');
            $table->dropColumn('possible_duplicate_reason');
        });
    }
};
