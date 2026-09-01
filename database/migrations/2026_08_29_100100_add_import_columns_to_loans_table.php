<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The two columns a loan carries when it was migrated in from a
     * cooperative's existing book rather than originated here.
     *
     * `external_loan_no` is the coop's OWN reference for the loan, kept in a
     * column of its own and deliberately NOT written into
     * `loan_account_number`. That column is a generated sequence:
     * LoanService::release() takes the next number with
     * `(int) substr(MAX(loan_account_number), 3) + 1`, which assumes the
     * `LN-000123` shape. Park an external format like `2023-0041` there and
     * that arithmetic reads `3-0041` out of it and yields 3, so the next
     * release issues `LN-000004` — a number that already exists — and the
     * unique index rejects it. Loan release would then be permanently broken on
     * that deployment, and unbreaking it would mean editing live account
     * numbers. Imported loans therefore leave `loan_account_number` NULL, which
     * release()'s `whereNotNull('loan_account_number')` already skips, so the
     * native sequence continues from the highest LN actually issued.
     *
     * Unique because it is the coop's identifier for a loan and the importer
     * needs the database — not just its own bookkeeping — to reject the same
     * loan arriving twice. Nullable so natively originated loans, which have no
     * external number, are unaffected (MySQL allows any number of NULLs in a
     * unique index).
     *
     * `imported_arrears_baseline` is the date before which this loan's arrears
     * are PRE-IMPORT and must not be penalised again. An imported loan lands
     * part-way through its life with due dates already in the past, so it is
     * immediately overdue by every measure in this system — but whatever
     * penalties the coop charged on those periods are already baked into the
     * balances they handed over. Charging them a second time double-bills a
     * real member.
     *
     * It lives on `loans`, not on the import tables, for two reasons:
     *
     *  1. It is read inside the penalty and default queries
     *     (AmortizationSchedule::penalisableSql()), which already have the
     *     `loans` row in scope. Deriving it from an import record would put a
     *     join into that path for every candidate loan.
     *  2. It has to outlive the import records. Deleting an import batch is a
     *     housekeeping action; it must not silently re-arm months of penalties
     *     on the loans that batch created.
     *
     * Indexed because it is a filter leg on those same queries.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('external_loan_no', 50)
                ->nullable()
                ->unique()
                ->after('loan_account_number');

            $table->date('imported_arrears_baseline')
                ->nullable()
                ->index()
                ->after('external_loan_no');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropUnique('loans_external_loan_no_unique');
            $table->dropIndex('loans_imported_arrears_baseline_index');
            $table->dropColumn(['external_loan_no', 'imported_arrears_baseline']);
        });
    }
};
