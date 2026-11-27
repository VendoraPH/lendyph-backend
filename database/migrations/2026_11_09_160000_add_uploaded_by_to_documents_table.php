<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `documents.uploaded_by`: the staff user who uploaded an accounting
     * attachment (Phase 2, accounting round C6). Nullable, and existing rows
     * stay null: member uploads don't set it. Restricted on delete, like
     * `audit_logs.user_id`, so the uploader of a kept file can't vanish.
     *
     * Adds one column and changes no row, so there is nothing to audit or
     * preview. Added only when missing, so running it again changes nothing.
     */
    public function up(): void
    {
        if (Schema::hasColumn('documents', 'uploaded_by')) {
            return;
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('uploaded_by')->nullable()->after('file_size')->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('documents', 'uploaded_by')) {
            return;
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('uploaded_by');
        });
    }
};
