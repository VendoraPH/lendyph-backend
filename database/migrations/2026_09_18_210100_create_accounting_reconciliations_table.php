<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proving one money account against its statement for one period.
 *
 * ## What is stored here, and what pointedly is not
 *
 * `statement_balance` is stored because it comes from OUTSIDE — the bank, GCash
 * or Maya said so, and nothing in this database can derive it.
 *
 * `book_balance` is NOT stored. It is computed from the ledger every time this
 * is read, and that is a decision rather than an omission. A reconciliation is
 * a working tool, not an archive: the whole point of the difference is to send
 * someone looking for the line that is missing, and when they find it and post
 * it, the difference has to CLOSE. A stored snapshot would keep reporting the
 * old gap after the fix, so the screen would still be amber and the only way to
 * clear it would be to redo the reconciliation — which teaches people to redo
 * reconciliations until the number looks right, the exact habit the notice on
 * that screen exists to discourage.
 *
 * MONEY IS IN CENTAVOS, and SIGNED here rather than unsigned: a money account
 * can be overdrawn, and a statement that says so has to be recordable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_reconciliations', function (Blueprint $table) {
            $table->id();

            // The money account being proved. restrictOnDelete — an account
            // with a reconciliation against it has history by definition.
            $table->foreignId('accounting_account_id')
                ->constrained('accounting_accounts')
                ->restrictOnDelete();

            // The label the card is titled with — "September 2026". Free text
            // rather than a foreign key to accounting_periods: a bank statement
            // period is the bank's, and it routinely does not line up with the
            // accounting month (statements cut on the 25th, or on a business
            // day). Tying the two would force one of them to lie.
            $table->string('period', 48);

            // The range whose ledger movement is being proved. These bound the
            // lines the matching engine considers, so they are what make
            // "unmatched" a fact about a window rather than about all history.
            $table->date('start_date');
            $table->date('end_date');

            // CENTAVOS, signed. What the bank/GCash/Maya statement says.
            $table->bigInteger('statement_balance');

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /*
             * One reconciliation per account per period.
             *
             * The Reconciliation screen keys its cards on
             * `${item.account_id}-${item.period}` — two rows sharing both would
             * collide in React's reconciler and render as one card, silently
             * dropping the other from a list whose purpose is completeness.
             * Enforced here rather than in a controller check because two
             * concurrent creates would both pass a SELECT.
             */
            $table->unique(['accounting_account_id', 'period'], 'accounting_reconciliations_account_period_unique');

            // The list is ordered end_date descending with id as the tiebreaker.
            $table->index(['end_date', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_reconciliations');
    }
};
