<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bank reconciliation statement line an expense was recorded from
 * (ReconciliationActions::recordAsExpense(), finish run round 6). Reversing
 * that expense frees the line while its reconciliation is open, and is
 * refused while it reads Reconciled; any other match is left alone.
 *
 * Adds a nullable column and its foreign key only: no row is written or
 * changed, so expenses recorded before stay unlinked and reverse as before.
 * A statement line deleted with its reconciliation leaves the expense
 * unlinked. Each step runs only when missing, so running it again changes
 * nothing.
 */
return new class extends Migration
{
    private const FOREIGN_KEY = 'accounting_expenses_reconciliation_line_id_foreign';

    public function up(): void
    {
        if (! Schema::hasColumn('accounting_expenses', 'reconciliation_line_id')) {
            Schema::table('accounting_expenses', function (Blueprint $table): void {
                $table->foreignId('reconciliation_line_id')->nullable()->after('journal_id')
                    ->constrained('accounting_reconciliation_lines', 'id', self::FOREIGN_KEY)
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accounting_expenses', 'reconciliation_line_id')) {
            Schema::table('accounting_expenses', function (Blueprint $table): void {
                $table->dropForeign(self::FOREIGN_KEY);
                $table->dropColumn('reconciliation_line_id');
            });
        }
    }
};
