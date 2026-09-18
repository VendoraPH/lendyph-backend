<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One settlement against an accrued expense.
 *
 * ## Why payments are rows and not just a running total
 *
 * `accounting_expenses.amount_paid` could have been incremented in place and
 * the money accounted for. It would also have made every payment journal
 * un-anchored: `JournalPoster::postImmediately()` is idempotent on
 * `(postable_type, postable_id, source)`, and both the accrual and its
 * settlement carry `source = 'payable'` (see `expense_accrual` and
 * `payable_payment` in src/lib/accounting/posting-rules.ts). Anchoring the
 * settlement to the Expense would therefore collide with the accrual's own
 * journal — and the collision does not fail loudly, it RESOLVES: the poster
 * returns the accrual entry it already wrote, the payment appears to have been
 * posted, and no money ever moves out of the cash account.
 *
 * Giving each payment its own row gives each payment journal its own postable,
 * so the idempotency guard protects a retried payment instead of silently
 * eating the first real one. That a partial payment history is worth keeping on
 * its own merits — three payments of ₱5,000 against a ₱15,000 bill are three
 * events, not one number — is the second reason, not the first.
 *
 * MONEY IS IN CENTAVOS, as unsigned BIGINTs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_expense_payments', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete, not cascade. A payment is a posted journal's
            // anchor; deleting the expense out from under it would orphan an
            // entry that is in the books and cannot be taken back out of them.
            $table->foreignId('accounting_expense_id')
                ->constrained('accounting_expenses')
                ->restrictOnDelete();

            // A DATE — the day the payment belongs to, not an instant.
            $table->date('date');

            // CENTAVOS.
            $table->unsignedBigInteger('amount');

            // The money account it came out of. Required: a payment that names
            // no source is a credit to nothing.
            $table->foreignId('payment_account_id')
                ->constrained('accounting_accounts')
                ->restrictOnDelete();

            // The `payable_payment` journal this settlement raised. See the
            // expenses table for why this restricts rather than nulls.
            $table->foreignId('journal_id')
                ->nullable()
                ->constrained('accounting_journals')
                ->restrictOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // "Every payment against this expense, oldest first" — the only way
            // this table is ever read.
            $table->index(['accounting_expense_id', 'date']);
        });

        // A payment moves money. Zero moves none, and JournalPoster would refuse
        // to post the entry for it anyway.
        DB::statement(
            'alter table `accounting_expense_payments` add constraint '
            .'`accounting_expense_payments_amount_positive_chk` check (`amount` > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_expense_payments');
    }
};
