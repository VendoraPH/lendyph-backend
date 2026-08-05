<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money movements against a loan, as debits and credits.
     *
     * The loan ledger was previously derived from `repayments` alone, so every
     * row was a credit and interest charged to the borrower had nowhere to
     * appear. Extending a loan accrues a fresh cycle of interest, which is a
     * debit — this table gives that a record.
     *
     * Entries are append-only: an extension adds to the ledger, it never
     * rewrites what came before.
     */
    public function up(): void
    {
        Schema::create('loan_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();

            // What produced the entry. Both nullable so other events (a plain
            // repayment, a penalty) can be recorded here later without either.
            $table->foreignId('loan_adjustment_id')->nullable()->constrained('loan_adjustments')->nullOnDelete();
            $table->foreignId('repayment_id')->nullable()->constrained('repayments')->nullOnDelete();

            $table->enum('type', ['debit', 'credit']);

            // Which balance the entry moves. Only interest is written today;
            // the column exists so principal and penalty can follow without a
            // second migration.
            $table->enum('category', ['principal', 'interest', 'penalty'])->default('interest');

            $table->decimal('amount', 12, 2);
            $table->date('entry_date');
            $table->string('description');
            $table->timestamps();

            $table->index(['loan_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_ledger_entries');
    }
};
