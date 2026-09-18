<?php

use App\Services\Accounting\CashFlowCategories;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which activity a movement through an account belongs to.
 *
 * ## The one thing a balance cannot tell you
 *
 * Operating, investing or financing is a judgement about what the money was
 * FOR, and no amount of arithmetic over the ledger recovers it. Releasing a
 * loan is investing for almost any business and is the core operation of a
 * lender; a member's deposit is financing here and revenue somewhere else. The
 * classification has to be stated, once, against the account — which is exactly
 * why the cash flow statement is the one statement the frontend cannot derive
 * from the trial balance and has to ask the server for.
 *
 * ## The default is a rule, not a table of exceptions
 *
 * {@see CashFlowCategories::defaultFor()} decides it from the account's own
 * type in one sentence, so that a reviewer can check the whole policy at a
 * glance rather than auditing sixty rows. The backfill below is that same rule
 * expressed as SQL, and it carries no exceptions the rule does not — see the
 * note at the end of `up()` for the one that was tried and removed.
 *
 * The defaults are DEFAULTS: every one is editable through
 * `PUT /accounting/accounts/{id}`, and the cash flow statement prints one line
 * per account under the heading it used, so a classification anybody disagrees
 * with is visible on the face of the report instead of buried in a subtotal.
 *
 * ## Nullable, and never actually null
 *
 * The column is nullable so this migration can add it to a populated table in
 * one statement, and the backfill below immediately fills it. From then on
 * AccountingAccount's saving hook derives it whenever it is missing, the same
 * way `normal_balance` is derived — so a new account always carries a
 * classification and can never be silently dropped out of a statement for
 * having none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_accounts', function (Blueprint $table) {
            $table->enum('cash_flow_category', CashFlowCategories::ALL)
                ->nullable()
                ->after('cash_kind');

            // The statement walks one category at a time, in code order.
            $table->index(['cash_flow_category', 'code']);
        });

        /*
         * Backfill, in four statements rather than a row-by-row loop.
         *
         * Ordered from the general to the specific: the type rule paints every
         * row, then the money accounts are overwritten, then the one exception
         * the rule cannot see. Running them in this order means each statement
         * is a plain UPDATE with no conditions about what came before.
         */

        // 1. The type rule. Income and expense are what the business does;
        //    everything else it owns is investing, everything else it owes or
        //    was given is financing.
        DB::table('accounting_accounts')->whereIn('type', ['income', 'expense'])
            ->update(['cash_flow_category' => 'operating']);
        DB::table('accounting_accounts')->where('type', 'asset')
            ->update(['cash_flow_category' => 'investing']);
        DB::table('accounting_accounts')->whereIn('type', ['liability', 'equity'])
            ->update(['cash_flow_category' => 'financing']);

        // 2. Cash itself is not an activity — it is the thing the statement
        //    explains. Classifying a cash account as investing would make every
        //    transfer between two of the organisation's own accounts appear as
        //    an investing flow in both directions.
        DB::table('accounting_accounts')->whereNotNull('cash_kind')
            ->update(['cash_flow_category' => 'cash']);

        /*
         * And that is the whole backfill. There are deliberately NO exceptions.
         *
         * An earlier draft of this migration special-cased Accounts Payable to
         * `operating`, on the reasoning that settling a trade payable is the
         * second half of an expense rather than a financing act — which is
         * true. It was removed, and the reason is worth recording, because the
         * argument for it will come back.
         *
         * A backfill only ever paints the rows that exist WHEN IT RUNS. A
         * freshly seeded chart is created afterwards, by
         * ChartOfAccountsSeeder, and takes its classification from
         * AccountingAccount's saving hook — which knows the account's type and
         * nothing else. So the exception applied on an already-migrated
         * deployment and not on a new one, and the same account carried a
         * different classification on two boxes running the same code. That is
         * the "code fixed is not fleet fixed" failure in miniature, and it is
         * the kind that produces two plausible cash flow statements with no way
         * to tell which is the odd one out.
         *
         * So the rule is the rule, everywhere, and CashFlowCategories is its
         * only statement. Accounts Payable therefore defaults to `financing`,
         * and it is the first classification an accountant should look at — the
         * statement prints it by code and name under that heading precisely so
         * that they can, and `PUT /accounting/accounts/{id}` is how they change
         * it.
         */
    }

    public function down(): void
    {
        Schema::table('accounting_accounts', function (Blueprint $table) {
            $table->dropIndex(['cash_flow_category', 'code']);
            $table->dropColumn('cash_flow_category');
        });
    }
};
