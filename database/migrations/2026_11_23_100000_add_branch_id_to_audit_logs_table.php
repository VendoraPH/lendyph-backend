<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 MB-BE6 (multi-branch decision 12): each new audit row records the
     * audited record's branch (AuditBranch). Old rows stay null; nothing is
     * backfilled.
     *
     * No foreign key, on purpose: audit rows are never edited, so a branch
     * delete must neither blank this column (null on delete) nor be blocked by
     * the trail (restrict). A deleted branch's id simply stays on its rows.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('auditable_id');
            $table->index(['branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'created_at']);
            $table->dropColumn('branch_id');
        });
    }
};
