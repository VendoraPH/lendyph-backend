<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-branch audit D10 (Phase 6 MB-BE2): deleting a branch in the
     * database blanked the branch of its members, journals and expenses (SET
     * NULL), while it was already refused for loans, savings and the rest. A
     * branch with history is closed (BranchStatus), never deleted, so these
     * three now refuse the delete too (RESTRICT). Nothing in the app deletes a
     * branch; this guards hand-run SQL and future code. No data changes.
     */
    private const TABLES = ['borrowers', 'accounting_journals', 'accounting_expenses'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['branch_id']);
                $blueprint->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['branch_id']);
                $blueprint->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            });
        }
    }
};
