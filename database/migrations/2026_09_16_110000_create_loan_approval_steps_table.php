<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side state for the multi-step BOD approval chain.
 *
 * Until this table the chain lived entirely in the browser's localStorage under
 * `loan-approval-{id}`: of a ten-step policy-exception chain exactly three
 * transitions ever reached the API (the first submit, the final approve, and
 * the release). Every intermediate signoff and every send-back was per-browser,
 * so a Manager approving on their laptop was invisible to BOD1 on theirs, a
 * cleared cache silently reset the chain, and none of it reached the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_approval_steps', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete, and deliberately NOT the restrictOnDelete that
            // loan_adjustments uses. LoanController@destroy HARD-deletes draft
            // loans, and a draft can carry chain rows: submit seeds the chain,
            // an approver sends it back, and the loan is then abandoned. Under
            // restrict that delete becomes a 500 on a perfectly ordinary
            // action. A chain row has no meaning without its loan, so it goes
            // with it.
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();

            // Revision rounds. Round 1 is the original pass; every send-back
            // freezes the current round and opens the next, so earlier rounds
            // stay readable as history rather than being overwritten.
            $table->unsignedSmallInteger('round')->default(1);
            $table->unsignedSmallInteger('step_order');

            // SNAPSHOTTED from ApprovalWorkflowSetting at seed time, not
            // looked up through it afterwards. An admin editing the chain in
            // /settings/approval-workflow must not silently rewrite the
            // approval history of a loan that is already in flight — a loan
            // keeps whatever chain it started with, which is also what the
            // frontend has always promised.
            $table->string('step_id', 100);
            $table->string('name', 255);
            $table->string('role', 100);
            $table->enum('kind', ['submit', 'approve', 'release']);

            $table->enum('status', ['waiting', 'pending', 'approved', 'sent_back'])->default('waiting');

            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamp('acted_at')->nullable();

            // Only ever set on the `sent_back` step that closes a round: which
            // step the loan went back TO. Persisted rather than derived because
            // once the next round starts moving, its shape no longer reveals
            // where it began — and the loan detail page prints the target's
            // name in the round summary.
            $table->unsignedSmallInteger('sent_back_to_step_order')->nullable();

            $table->timestamps();

            // One row per position per round — the guard against a double
            // seed or a concurrent send-back duplicating a round.
            $table->unique(['loan_id', 'round', 'step_order']);

            // "which step is pending for this loan" is the hot read, on both
            // the loan detail page and every action's authorization check.
            $table->index(['loan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_approval_steps');
    }
};
