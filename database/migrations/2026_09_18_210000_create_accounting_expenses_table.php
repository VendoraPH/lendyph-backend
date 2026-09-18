<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operating costs, paid on the spot or owed.
 *
 * This table is the SUBSIDIARY record behind two journal shapes, never a
 * substitute for them. Recording an expense always writes a journal in the same
 * transaction as the row below — `expense_cash` when it was paid there and then,
 * `expense_accrual` when it was not — so the Expenses screen and the income
 * statement cannot disagree about what the organisation spent. A row here with
 * no `journal_id` would be a cost that exists on one screen and nowhere in the
 * books, which is why the column is populated on insert and why
 * ExpenseRecorder refuses outright when the account it needs is unmapped.
 *
 * MONEY IS IN CENTAVOS, as unsigned BIGINTs — the accounting convention, NOT the
 * `decimal:2` the lending tables use. ₱15,000.50 is 1500050 here. A `decimal:2`
 * column would serialise to JSON as the string "15000.50", and the Expenses
 * screen sums `amount - amount_paid` client-side into a headline "Outstanding"
 * figure; string arithmetic there produces either NaN or a 100x error, and both
 * render without complaint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_expenses', function (Blueprint $table) {
            $table->id();

            // A DATE, not a timestamp: the accounting date this cost belongs
            // to, chosen by the person recording it and routinely not today.
            // The same reasoning as `accounting_journals.date`, and the same
            // reason neither appears in TimezoneShift::COLUMNS.
            $table->date('date');

            $table->string('payee', 160);

            // The 5xxx account the cost lands in. restrictOnDelete, like every
            // other account reference: an account with history may be
            // deactivated but never deleted, or the expense would point at
            // nothing while its journal line still existed.
            $table->foreignId('expense_account_id')
                ->constrained('accounting_accounts')
                ->restrictOnDelete();

            // CENTAVOS. `amount` is what was incurred; `amount_paid` is how much
            // of it has been settled, and the difference is what the screen
            // shows as the balance owed.
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('amount_paid')->default(0);

            // The money account it was paid FROM, set only when the expense was
            // paid on the spot. NULL means it was accrued and is owed — the
            // distinction the "Paid now?" switch in the dialog makes, and the
            // one thing that decides which journal shape was written.
            $table->foreignId('payment_account_id')
                ->nullable()
                ->constrained('accounting_accounts')
                ->restrictOnDelete();

            // nullOnDelete: closing a branch must not delete its costs.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('reference', 64)->nullable();
            $table->string('description', 500)->nullable();

            // Another user-supplied calendar date. Only meaningful on an
            // accrued expense; a cash expense is settled the moment it exists.
            $table->date('due_date')->nullable();

            /*
             * The SETTLEMENT state, and only that.
             *
             * `overdue` is deliberately NOT stored, though it IS one of the four
             * values `ExpenseStatus` in src/types/accounting.ts carries and one
             * of the four the status filter offers. Overdue is a function of
             * today's date against `due_date`, so storing it would be a claim
             * that goes stale overnight and would need a daily job whose only
             * purpose is to keep a derived column true. It is derived on read in
             * AccountingExpense::status(), from these amounts and `due_date`.
             */
            $table->enum('settlement_status', ['unpaid', 'partially_paid', 'paid'])->default('unpaid');

            // The journal this expense raised — the `expense_cash` entry when it
            // was paid on the spot, the `expense_accrual` entry when it was not.
            // Nullable because the column has to exist before the journal does
            // (the row is inserted, then posted against, inside one
            // transaction); it is never left null by any code path here.
            //
            // restrictOnDelete rather than nullOnDelete: a posted journal is not
            // deletable in the first place (AccountingJournal refuses), and if
            // one ever were, quietly unlinking the expense would leave a cost on
            // this screen whose entry in the books had vanished with nothing to
            // say so.
            $table->foreignId('journal_id')
                ->nullable()
                ->constrained('accounting_journals')
                ->restrictOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The list is ordered by date descending with id as the tiebreaker —
            // see AccountingExpenseController::index(), where a total order
            // across pages is what stops a drained list duplicating or skipping
            // rows between page 1 and page 2.
            $table->index(['date', 'id']);

            // The status filter, and the overdue derivation, both read these.
            $table->index('settlement_status');
            $table->index('due_date');

            $table->index(['branch_id', 'date']);
        });

        /*
         * You cannot pay more than you owe.
         *
         * In the database rather than only in the service because `amount_paid`
         * is the figure the Expenses screen subtracts to show an outstanding
         * balance. An over-payment would make that balance NEGATIVE and quietly
         * REDUCE the headline "Outstanding" total for every other payable on the
         * screen — a smaller, calmer number produced by a bug. The service takes
         * a row lock and checks the same thing with a message a screen can
         * render; this is what holds when something skips the service.
         */
        DB::statement(
            'alter table `accounting_expenses` add constraint `accounting_expenses_paid_within_amount_chk` '
            .'check (`amount_paid` <= `amount`)'
        );

        /*
         * An expense records something. A zero-peso cost has no journal that
         * could be written for it — JournalPoster refuses to post an entry that
         * moves no money — so a row here with amount 0 could only ever be one
         * whose journal failed.
         */
        DB::statement(
            'alter table `accounting_expenses` add constraint `accounting_expenses_amount_positive_chk` '
            .'check (`amount` > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_expenses');
    }
};
