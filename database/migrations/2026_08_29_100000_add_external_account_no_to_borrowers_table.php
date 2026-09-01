<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The coop's own account number for a member, carried over from the legacy
     * system during a CSV migration.
     *
     * Two files arrive per migration — one of customers, one of loans — and the
     * only thing that ties a loan row back to the member it belongs to is this
     * number. It is the join key, so it has to live on the borrower rather than
     * in a scratch table that gets thrown away when the run finishes.
     *
     * It is also the backstop against a double import. An admin who uploads the
     * same customer file twice (or resumes a run that had already written some
     * rows) must not end up with two borrowers for one member: the second
     * attempt looks the number up, finds the row its own first attempt wrote,
     * and reports `matched_existing` instead of inserting a twin.
     *
     * Nullable and unique, exactly as `registration_uuid` is: MySQL permits any
     * number of NULLs under a unique index, so every borrower already on file
     * and every future walk-in registration — none of which came from the
     * legacy system — is unaffected. The index is also what makes the check
     * safe when two importer workers race the same row: one insert wins and the
     * other is told which id won, rather than both passing a lookup and both
     * inserting.
     *
     * varchar(50) because legacy account numbers are strings, not integers.
     * They carry branch prefixes, dashes and leading zeros, and casting one to
     * a number would silently turn `00123` into `123` and break the join.
     */
    public function up(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->string('external_account_no', 50)->nullable()->unique()->after('registration_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->dropUnique(['external_account_no']);
            $table->dropColumn('external_account_no');
        });
    }
};
