<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The journal header — one double-entry transaction.
 *
 * A journal is the only thing that may move money in this system. Every figure
 * on every statement is a sum over the lines hanging off these rows, so the
 * shape of this table is what makes the books provable rather than merely
 * plausible.
 *
 * MONEY IS IN CENTAVOS, as unsigned BIGINTs. Not DECIMAL, and emphatically not
 * a float: a posted journal must satisfy `total_debit = total_credit` EXACTLY,
 * and the database enforces that below with a CHECK. Integers make that
 * enforceable; binary floating point does not.
 */
return new class extends Migration
{
    /**
     * Every `JournalSource` in `src/types/accounting.ts`, plus `share_capital`.
     *
     * `share_capital` is NOT in the TypeScript union. It is here because the
     * share-capital ledger already exists in this application and its postings
     * have no honest source otherwise — filing a member's capital contribution
     * under `manual` or `cash_in` would make it untraceable from the statement
     * back to the ledger entry that caused it, which is the entire point of
     * recording a source. The frontend union needs the same value added; until
     * it does, this is the one deliberate difference between the two lists.
     */
    private const SOURCES = [
        'loan_release',
        'loan_collection',
        'penalty',
        'loan_fee',
        'gcash',
        'cash_transaction',
        'bank_transaction',
        'cash_in',
        'cash_out',
        'expense',
        'payable',
        'transfer',
        'manual',
        'adjustment',
        'reversal',
        'opening_balance',
        'credit_loss',
        'share_capital',
    ];

    public function up(): void
    {
        Schema::create('accounting_journals', function (Blueprint $table) {
            $table->id();

            // "JE-000154". NULLABLE and unique at once, on purpose: a draft has
            // no number because it is not yet part of the books, and MySQL
            // treats NULLs as distinct in a unique index, so any number of
            // drafts coexist while every posted entry holds a number nothing
            // else can hold. Allocating on post rather than on create is what
            // keeps the sequence gap-free — a draft someone abandons would
            // otherwise burn a number out of the middle of the register.
            $table->string('journal_no', 16)->nullable()->unique();

            // A DATE, not a timestamp. The accounting date is the calendar day
            // the transaction belongs to — chosen by the person posting it, and
            // routinely not today. It is never timezone-derived, which is also
            // why this table's date column is not in TimezoneShift::COLUMNS.
            $table->date('date');

            $table->enum('source', self::SOURCES);

            // The originating document: "COL-10254", "LN-000154".
            $table->string('reference', 64)->nullable();

            $table->string('description', 500);

            // nullOnDelete: a closed branch must not take its history with it.
            // The entries stay on the books and simply stop naming a branch.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->enum('status', ['draft', 'posted', 'reversed'])->default('draft');

            // Centavos. Recomputed from the persisted lines on post and never
            // taken from the client — see JournalPoster::post(). Defaulted to 0
            // so a draft with no lines yet is still a valid row.
            $table->unsignedBigInteger('total_debit')->default(0);
            $table->unsignedBigInteger('total_credit')->default(0);

            // The two halves of a reversal, pointing at each other. Both
            // nullOnDelete rather than cascade: neither entry may ever be
            // deleted (the model refuses, and so should anyone), but if one
            // somehow goes, the survivor must stay on the books rather than be
            // dragged out of them.
            $table->foreignId('reverses_journal_id')->nullable()->constrained('accounting_journals')->nullOnDelete();
            $table->foreignId('reversed_by_journal_id')->nullable()->constrained('accounting_journals')->nullOnDelete();

            // What caused this entry — a Loan, a Repayment, a GCashTransaction.
            // Null for manual entries, which are caused by a person. Also adds
            // index(postable_type, postable_id) for "show me this loan's
            // journals".
            $table->nullableMorphs('postable');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();

            // A real instant, unlike `date`: when the entry entered the books.
            $table->dateTime('posted_at')->nullable();

            $table->timestamps();

            // The journal register, which is filtered by status and ordered by
            // date on every screen that shows it.
            $table->index(['status', 'date']);

            // Reporting reads by date alone across every status the trial
            // balance counts, so the composite above cannot serve it.
            $table->index('date');

            $table->index('source');

            $table->index(['branch_id', 'date']);
        });

        /*
         * THE IDEMPOTENCY GUARD.
         *
         * One automatic posting per (document, source). An automatic posting is
         * raised in the same transaction as the lending event that causes it, so
         * it will sometimes be retried — a deploy, a timeout, a queue redelivery,
         * an operator double-click. Without this index the retry writes a SECOND
         * balanced journal: the trial balance still balances, every statement is
         * still internally consistent, and the portfolio is simply double-counted
         * with nothing anywhere to point at the difference. That is the worst
         * failure mode this module has, because nothing about it looks broken.
         *
         * With the index the retry hits a unique violation instead, which
         * JournalPoster::postImmediately() resolves by returning the journal that
         * already exists. The guard lives in the database rather than in a
         * controller check because two concurrent retries would both pass a
         * SELECT and both then INSERT.
         *
         * Keyed on `source` as well as the document because one document
         * legitimately raises several entries: a loan is released, collected
         * against, and charged a fee, and those are three journals against one
         * Loan. Manual entries carry NULL here and MySQL treats NULLs as
         * distinct, so they are unaffected.
         *
         * Declared outside the Blueprint because `nullableMorphs()` has to have
         * created the columns first.
         */
        Schema::table('accounting_journals', function (Blueprint $table) {
            $table->unique(
                ['postable_type', 'postable_id', 'source'],
                'accounting_journals_postable_source_unique',
            );
        });

        /*
         * A posted journal balances. Enforced by the database, not only by the
         * poster.
         *
         * JournalPoster is the only writer that should ever exist, and it
         * recomputes both totals from the persisted lines before it allows a
         * post. This constraint is what makes that a guarantee rather than a
         * convention: a future importer, a console command, a manual UPDATE
         * during an incident, or a second posting path written in a hurry
         * cannot put an unbalanced entry into the books, because MySQL 8.0.16+
         * refuses the row outright.
         *
         * Stated as "a DRAFT may be unbalanced" rather than "a POSTED one must
         * balance", and the difference is not cosmetic. The narrower spelling
         * (`status <> 'posted' OR ...`) leaves `reversed` unconstrained, so an
         * unbalanced draft moved straight to `reversed` by a stray UPDATE would
         * satisfy it — and `AccountingJournal::scopeHistorical()` counts
         * `reversed`, so that row would reach the trial balance and unbalance
         * the books. No code path does that today: the only route to `reversed`
         * is JournalPoster::reverse(), which requires `posted` first. This is
         * defence in depth against the route that does not exist yet.
         *
         * A draft is explicitly allowed to be out of balance, because someone
         * is still typing it.
         */
        DB::statement(
            'alter table `accounting_journals` add constraint `accounting_journals_posted_balanced_chk` '
            ."check (`status` = 'draft' or `total_debit` = `total_credit`)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_journals');
    }
};
