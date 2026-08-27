<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client-supplied idempotency key for a public registration submission.
     *
     * A coop member submitted the form from a Facebook in-app browser, the
     * response was lost to a 30-second timeout, and the retry was rejected as a
     * duplicate of the row their own first attempt had already written. The
     * frontend caches the created borrower, but only once it has actually SEEN
     * the response — a lost response defeats it. The key travels with the
     * request instead, so the second attempt can be recognised as the same
     * submission rather than a second applicant.
     *
     * Nullable and unique: MySQL permits any number of NULLs under a unique
     * index, so every operator-created row and every borrower already on file
     * is unaffected. The index is also the arbiter for two retries racing each
     * other past the lookup — one insert wins, the other is told which id won.
     */
    public function up(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->uuid('registration_uuid')->nullable()->unique()->after('borrower_code');
        });
    }

    public function down(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->dropUnique(['registration_uuid']);
            $table->dropColumn('registration_uuid');
        });
    }
};
