<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `edit_version` on journals: every draft edit through JournalPoster
     * moves it by one. Posting, editing and reversing compare it right after
     * the journal row lock, so an edit that landed after their unlocked read
     * of the lines (which decides on the allowance account lock) is refused
     * instead of breaking the lock order.
     *
     * Adds one column, default 0: no row's figures change, so there is
     * nothing to audit or preview. Added only when missing, so running it
     * again changes nothing.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('accounting_journals', 'edit_version')) {
            Schema::table('accounting_journals', function (Blueprint $table): void {
                $table->unsignedInteger('edit_version')->default(0);
            });
        }
    }

    /** Nothing to undo: the column holds no figure, and dropping it would only lose the check. */
    public function down(): void {}
};
