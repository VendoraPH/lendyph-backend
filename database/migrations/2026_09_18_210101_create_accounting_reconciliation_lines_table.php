<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One line off the external statement, and the ledger line it was paired with.
 *
 * ## Only the STATEMENT side lives here
 *
 * The other side of a reconciliation — what the ledger says — already exists as
 * `accounting_journal_lines`, and copying those rows into this table would
 * create a second version of them that could drift from the first. So the
 * matching engine reads the ledger live and this table holds only what the
 * ledger cannot supply: the lines the bank sent, and the pairings someone
 * confirmed.
 *
 * A row with `matched_journal_line_id` set is a CONFIRMED pairing — a human
 * said these two are the same event. Everything else is the engine's opinion,
 * recomputed on every read and never written down, because a suggestion that
 * persisted would be indistinguishable from a decision.
 *
 * MONEY IS IN CENTAVOS, and SIGNED: positive is money IN, which is the
 * convention `ReconciliationLine.amount` in src/types/accounting.ts states.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_reconciliation_lines', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete, unlike almost every other constraint in this
            // module — and the exception is principled. These rows are typed-in
            // statement lines, not postings; they move no money, appear on no
            // statement, and have no meaning apart from the reconciliation they
            // belong to. Deleting the parent and leaving them would leave rows
            // nothing can reach.
            // Named explicitly. Laravel's generated name for this one —
            // "accounting_reconciliation_lines_accounting_reconciliation_id_foreign",
            // 66 characters — runs past MySQL's 64-character identifier limit
            // and the migration fails outright. The journal lines table
            // documents the same trap for the same reason.
            $table->unsignedBigInteger('accounting_reconciliation_id');
            $table->foreign('accounting_reconciliation_id', 'acct_recon_lines_recon_fk')
                ->references('id')->on('accounting_reconciliations')
                ->cascadeOnDelete();

            // The date the STATEMENT gives, which is routinely a day or two off
            // the date the ledger gives for the same event. That gap is the
            // whole reason the engine matches on a window rather than on
            // equality.
            $table->date('date');

            $table->string('description', 500);

            // CENTAVOS, signed. Positive is money in.
            $table->bigInteger('amount');

            // The bank's own reference. When it matches a journal's
            // `reference`, that is the strongest signal the engine has.
            $table->string('external_reference', 64)->nullable();

            /*
             * The confirmed pairing.
             *
             * UNIQUE, so one ledger line cannot be claimed by two statement
             * lines. Without it a ₱5,000 deposit appearing twice on a statement
             * could both be matched to the single ledger entry, and the
             * reconciliation would report itself clean while one of the two
             * deposits was genuinely missing from the books.
             *
             * nullOnDelete: a journal line cannot be deleted while its journal
             * stands, but if one ever were, the statement line must survive as
             * an unmatched item rather than disappear with it.
             */
            $table->unsignedBigInteger('matched_journal_line_id')->nullable();
            $table->foreign('matched_journal_line_id', 'acct_recon_lines_journal_line_fk')
                ->references('id')->on('accounting_journal_lines')
                ->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique('matched_journal_line_id', 'acct_recon_lines_journal_line_unique');

            // Named for the same length reason as the foreign key above.
            $table->index(['accounting_reconciliation_id', 'date'], 'acct_recon_lines_recon_date_idx');
        });

        /*
         * A statement line records a movement. Zero is not a movement, and a
         * zero-amount row would match every other zero-amount row on amount
         * alone — turning the engine's one hard signal into noise.
         */
        DB::statement(
            'alter table `accounting_reconciliation_lines` add constraint '
            .'`acct_recon_lines_amount_nonzero_chk` check (`amount` <> 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_reconciliation_lines');
    }
};
