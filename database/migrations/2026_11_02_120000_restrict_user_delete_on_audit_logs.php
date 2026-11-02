<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `audit_logs.user_id` names who made each change. With `nullOnDelete`,
 * deleting a user blanked the author on every row they wrote, and audit rows
 * are never edited. Restrict the delete instead: a user who wrote audit rows
 * can't be deleted. No user delete path exists in the app today.
 *
 * Only the foreign key changes; no row is read or written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
