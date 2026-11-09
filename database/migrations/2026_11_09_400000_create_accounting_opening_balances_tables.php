<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The opening balances screen's draft (Phase 2, Accounting round C, slice C2).
 *
 * One entry per deployment, with no branch: the `singleton` column is always
 * 1 and UNIQUE, so a second draft cannot exist and two racing first saves
 * collide on the index instead of both inserting. The lines are replaced
 * wholesale on every save, one line per account. Finalising posts the draft
 * once, through OpeningBalancePoster, and links the journal here.
 *
 * Only adds tables, and runs safely twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_opening_balances')) {
            Schema::create('accounting_opening_balances', function (Blueprint $table): void {
                $table->id();
                $table->unsignedTinyInteger('singleton')->default(1)->unique();
                $table->date('as_of');
                $table->enum('status', ['draft', 'finalised'])->default('draft');
                $table->foreignId('accounting_journal_id')->nullable()
                    ->constrained('accounting_journals', indexName: 'opening_balances_journal_foreign')
                    ->restrictOnDelete();
                $table->unsignedInteger('edit_version')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->foreignId('finalised_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('finalised_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('accounting_opening_balance_lines')) {
            Schema::create('accounting_opening_balance_lines', function (Blueprint $table): void {
                $table->id();
                // Named: the generated names run past MySQL's 64 characters.
                $table->foreignId('accounting_opening_balance_id')
                    ->constrained('accounting_opening_balances', indexName: 'opening_balance_lines_draft_foreign')
                    ->cascadeOnDelete();
                $table->foreignId('accounting_account_id')
                    ->constrained('accounting_accounts', indexName: 'opening_balance_lines_account_foreign')
                    ->restrictOnDelete();
                $table->unsignedBigInteger('debit')->default(0);
                $table->unsignedBigInteger('credit')->default(0);
                $table->timestamps();

                $table->unique(['accounting_opening_balance_id', 'accounting_account_id'], 'opening_balance_lines_account_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_opening_balance_lines');
        Schema::dropIfExists('accounting_opening_balances');
    }
};
