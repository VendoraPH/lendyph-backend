<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The allowance for credit losses, posted (decision 12): one row per branch
 * per run, each keyed to the `credit_loss` journal that moved that branch's
 * 1200 balance to what its past-due loans require.
 *
 * MONEY IS IN CENTAVOS, signed: `adjustment` is positive when it provides and
 * negative when it releases, and a branch's 1200 balance can stand on the
 * wrong side. `required` and `previous_balance` are the figures the run used,
 * kept as they were, so a later change to a loan never rewrites a posting.
 *
 * Only adds a table, so it is safe to run twice and writes no audit row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_allowance_postings')) {
            return;
        }

        Schema::create('accounting_allowance_postings', function (Blueprint $table) {
            $table->id();
            // One run posts every branch that changes; its rows share this id.
            $table->uuid('run_id')->index();
            $table->date('as_of');
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->bigInteger('required');
            $table->bigInteger('previous_balance');
            $table->bigInteger('adjustment');
            // Null only while the run is writing it, inside the transaction.
            $table->foreignId('accounting_journal_id')->nullable()->constrained('accounting_journals')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['run_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_allowance_postings');
    }
};
