<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 MB-BE5 (multi-branch decision 9): money moved from one branch's
     * money account to another's through 1190 Interbranch Clearing.
     *
     * - `amount` is integer centavos, like every accounting table.
     * - The four journal columns link the transfer to the two legs it posted
     *   (dispatch in the source branch on approval, receipt in the destination
     *   on receipt) and to their reversals. Each journal also carries the
     *   transfer number as its reference.
     * - `transfer_number` (IBT-000001) is set from the id right after insert,
     *   so two saves can never take the same number.
     *
     * Listed in TimezoneShift::EXCLUDED_COLUMNS (added after the one-shot
     * shift).
     */
    public function up(): void
    {
        Schema::create('interbranch_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number', 20)->nullable()->unique();
            $table->string('status', 20)->default('pending_approval');
            $table->foreignId('source_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('destination_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('from_account_id')->constrained('accounting_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('accounting_accounts')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('purpose', 500);
            $table->string('reference', 64)->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('dispatch_journal_id')->nullable()->constrained('accounting_journals')->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('receipt_journal_id')->nullable()->constrained('accounting_journals')->restrictOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_note', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->foreignId('dispatch_reversal_journal_id')->nullable()->constrained('accounting_journals')->restrictOnDelete();
            $table->foreignId('receipt_reversal_journal_id')->nullable()->constrained('accounting_journals')->restrictOnDelete();
            $table->timestamps();

            $table->index(['source_branch_id', 'status']);
            $table->index(['destination_branch_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interbranch_transfers');
    }
};
