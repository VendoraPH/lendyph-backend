<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One side of one entry. Every peso on every statement is a sum over this table.
 *
 * Centavos as unsigned BIGINTs, for the reason the header migration gives:
 * `total_debit = total_credit` has to hold exactly, and it is these rows the
 * totals are recomputed from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_journal_lines', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete, which sounds alarming next to an immutable
            // ledger and is not: only a DRAFT journal can ever be deleted (see
            // AccountingJournal::booted()), and a draft's lines have no meaning
            // without it. The alternative — restrict — would leave orphan lines
            // behind every abandoned draft, and those lines are indistinguishable
            // from real ones in every aggregate that does not join the header.
            $table->foreignId('accounting_journal_id')
                ->constrained('accounting_journals')
                ->cascadeOnDelete();

            // restrictOnDelete, and this is the load-bearing one.
            //
            // It is what makes "this account has transactions" TRUE AT THE
            // DATABASE rather than merely checked in a controller. Deleting an
            // account that has been posted to would destroy one half of entries
            // that still exist — the books would stop balancing, and the missing
            // side would be unrecoverable. A controller check is a race and a
            // code path; this is neither.
            $table->foreignId('accounting_account_id')
                ->constrained('accounting_accounts')
                ->restrictOnDelete();

            // Presentation order within the entry, 1-based. Stored rather than
            // derived from `id` so a reversal's lines can be written in the
            // same order as the original's and read back that way.
            $table->unsignedSmallInteger('line_no');

            $table->string('description', 255)->nullable();

            // Centavos. Exactly one of these is non-zero — see the CHECK below.
            $table->unsignedBigInteger('debit');
            $table->unsignedBigInteger('credit');

            $table->timestamps();

            // No two lines share a position within an entry.
            //
            // Both indexes are named explicitly because Laravel's generated
            // names ("accounting_journal_lines_accounting_account_id_accounting
            // _journal_id_index") run past MySQL's 64-character identifier
            // limit and the migration fails outright.
            $table->unique(['accounting_journal_id', 'line_no'], 'acct_journal_lines_journal_line_no_unique');

            // The general ledger and the trial balance both read
            // "every line for this account", then join the header for its date
            // and status. Account first because that is the selective half.
            $table->index(['accounting_account_id', 'accounting_journal_id'], 'acct_journal_lines_account_journal_idx');
        });

        /*
         * EXACTLY ONE SIDE, AND NEVER ZERO. Both rules in one expression.
         *
         * `(debit = 0) <> (credit = 0)` is true only when exactly one of the two
         * is zero, which rules out both of the shapes `posting-rules.ts` asserts
         * against on every rule it has:
         *
         *   - debit AND credit both non-zero — a line that is somehow both sides
         *     at once. It still sums correctly into both totals, so an entry
         *     built from such lines BALANCES, and the trial balance reports
         *     healthy books while the account's ledger shows a movement that
         *     never happened in either direction.
         *   - debit AND credit both zero — a line recording nothing. Harmless
         *     arithmetically and dishonest on the page: it puts an account into
         *     an entry it took no part in, and into the general ledger of that
         *     account with a running balance that does not move.
         *
         * Neither shape raises an error anywhere downstream, which is exactly
         * why the rule belongs in the database and not only in a validator. The
         * columns are already UNSIGNED, so negatives are refused by the type —
         * direction belongs to the column, not the sign.
         */
        DB::statement(
            'alter table `accounting_journal_lines` add constraint `accounting_journal_lines_one_side_chk` '
            .'check ((`debit` = 0) <> (`credit` = 0))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_journal_lines');
    }
};
