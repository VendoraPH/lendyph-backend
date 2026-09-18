<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether this account is holding a password somebody else chose for it.
     *
     * `POST /users/{user}/reset-password` hands an administrator the ability to
     * set another person's password and then signs that person out. Until now
     * nothing made them replace it afterwards, so the admin-chosen string stayed
     * live indefinitely — a shared secret that two people know, one of whom
     * never agreed to it. This flag is what turns that reset into a temporary
     * credential: `RequirePasswordChange` refuses every authenticated request
     * while it is true, and only `AuthController::changePassword` clears it.
     *
     * Boolean rather than a `password_reset_at` timestamp on purpose. A
     * timestamp invites a comparison ("reset after the last change?") that has
     * to be re-derived at every call site and gets the clock-skew and
     * same-second cases wrong; the question being asked is binary and the
     * answer is only ever written by two code paths.
     *
     * DEFAULT false, and `Schema::table` backfills every existing row with it,
     * so nobody who has not actually had their password reset is locked out by
     * this migration running. That is the single most important property here:
     * a bad default would lock every user of every deployment out of the
     * product on deploy, including the administrators who would have to undo it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
