<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A revocation counter for the signed KYC file links minted on a user's behalf.
 *
 * Signed file URLs are authenticated by their signature alone — they have to
 * be, because they are consumed by <img src>, which cannot send an
 * Authorization header. That made them the one credential in the system a
 * password reset could not take away: `resetPassword()` deletes every token the
 * user holds, but a link already handed out stayed valid for the remainder of
 * its 30-minute window and kept streaming borrower identity documents.
 *
 * Links now carry the minting user's id and the value of this counter, both
 * inside the signature so neither can be stripped or edited. Bumping it
 * invalidates every link minted for that user, at once. See
 * App\Services\SignedFileLink.
 *
 * Deliberately an integer rather than a `file_links_revoked_at` timestamp: a
 * counter needs no clock comparison, cannot be confused by the UTC → Asia/Manila
 * cutover, and does not have to be registered in App\Services\TimezoneShift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('file_link_version')->default(0)->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('file_link_version');
        });
    }
};
