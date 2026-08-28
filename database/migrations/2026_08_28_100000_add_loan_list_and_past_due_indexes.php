<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the four access paths the loans list and the past-due filter use
 * that no existing index serves.
 *
 * Preventative, not remedial: a single-tenant coop database holds tens of loans
 * today, and MySQL will table-scan those faster than it reads an index. The
 * reason to add them now is that every one of these paths is a full scan whose
 * cost grows with the book, and the cheapest moment to add an index to a table
 * is while the table is small — this migration rewrites nothing and blocks
 * nothing at these row counts, whereas the same four statements against a
 * mature book would not be free.
 *
 * Measured on a throwaway copy loaded with 50,000 loans and 300,000 schedules,
 * the first page of the loans list (`ORDER BY created_at DESC, id DESC LIMIT 15`,
 * plain and with the Active tab's status filter) went from 50,001 sequential row
 * reads plus a filesort to zero of each — it reads the index backwards and stops
 * after fifteen. That is the shape of the cost this is buying out of the future.
 *
 * Each `up()` statement is guarded on Schema::hasIndex() and each `down()`
 * statement likewise, so this is safe to land alongside another branch that
 * happens to add an overlapping index, and safe to re-run on a box whose
 * migration history has drifted.
 */
return new class extends Migration
{
    /**
     * Indexes to create, keyed by table.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const INDEXES = [
        'loans' => [
            /*
             * `sort=created_at` is the list's DEFAULT sort, and `date_from` /
             * `date_to` filter the same column as a range. Neither had an index
             * of any kind — `loans` indexes borrower_id, branch_id, status,
             * loan_product_id and auto_pay only.
             *
             * Composite with `id` rather than a bare `created_at` because
             * LoanController::applySort() appends `orderBy('loans.id', $dir)` as
             * a deterministic tiebreak on EVERY sort, in the same direction. A
             * bare `created_at` index leaves that second key unserved and MySQL
             * still files a sort; `(created_at, id)` matches the ORDER BY whole,
             * in either direction, so the paginator can walk the index.
             */
            'loans_created_at_id_index' => ['created_at', 'id'],

            /*
             * The tabs on the loans screen are a status filter and the rows
             * under them are ordered by created_at, so the two arrive together
             * on nearly every request. Neither single-column index serves that
             * pair: `status` alone filters but leaves the sort, and
             * `(created_at, id)` above sorts but leaves the filter.
             *
             * Status leads because it is the equality leg and created_at is the
             * ordering/range leg — reversing them would end the usable prefix at
             * the range column. A single-status tab gets both filter and order
             * from this index. The Active tab sends several statuses at once
             * (Loan::ACTIVE_STATUSES), so it gets the filter from the index and
             * still sorts, but over a slice instead of the whole table.
             */
            'loans_status_created_at_index' => ['status', 'created_at'],
        ],

        'loan_products' => [
            /*
             * `loan_products` indexed `status` only, so `name` — which two
             * separate paths order by — had nothing behind it.
             *
             * The path this actually fixes is LoanProductController::index(),
             * whose `orderBy('name')->get()` is UNPAGINATED: measured on 5,005
             * products it read every row and filesorted them, and reads 15 off
             * the index now (`type: index`, no `Using filesort`).
             *
             * It does NOT fix `sort=product` on the loans list, which was the
             * reason this index was proposed. Measured, that plan is unchanged.
             * The list LEFT JOINs loan_products and orders by the JOINED table's
             * column, and a LEFT JOIN pins `loans` first in join order, so MySQL
             * can only satisfy that ORDER BY by materialising and sorting the
             * whole result (`Using temporary; Using filesort` on ~50k loans,
             * with or without this index). Removing that filesort needs a
             * different change — a loans-side sort column — not an index here.
             */
            'loan_products_name_index' => ['name'],
        ],

        'amortization_schedules' => [
            /*
             * Serves the past-due filter, which reaches this table as a
             * correlated EXISTS from the loans list:
             *
             *   EXISTS (SELECT * FROM amortization_schedules
             *           WHERE loan_id = loans.id
             *             AND status IN (...)
             *             AND due_date < ?)
             *
             * The table indexes `loan_id` and `due_date` SEPARATELY plus a
             * unique on (loan_id, period_number); MySQL can use exactly one of
             * them per table reference, so the EXISTS reads every schedule row
             * of a loan and filters status and due_date by hand.
             *
             * Column order, equality before range:
             *
             *  1. `loan_id` — the correlation supplies it as an equality
             *     predicate, once per outer row. It is the only leg that can
             *     narrow the scan to one loan's schedule, and an index cannot be
             *     used for the correlation at all unless it is leftmost.
             *  2. `status` — an IN list, which MySQL dives once per listed
             *     value, so it still behaves as an equality leg and extends the
             *     usable prefix.
             *  3. `due_date` — the inequality. A range leg terminates the usable
             *     prefix, so anything after it would be a post-filter, which is
             *     why it goes last. Putting due_date second would demote status
             *     to exactly that.
             *
             * Measured on 50k loans / 300k schedules, this flips the plan from
             * materialising every schedule row (`MATERIALIZED`, `type: ALL`,
             * rows 298,220) to a covering `LooseScan` on this index
             * (`Using index` — the three legs are the whole predicate, so no
             * row lookups at all).
             *
             * Note for the caller: `whereDate('due_date', ...)` wraps the column
             * in DATE(), which cannot be used as a range bound. Because this
             * index happens to be covering, the measured cost of that today is
             * nil — the filter is still evaluated from index data. Prefer
             * `where('due_date', '<', today())` anyway: `due_date` is a DATE
             * column with no time component to strip, so the two are equivalent,
             * and the plain form keeps the third leg usable if the predicate set
             * ever changes and the index stops covering.
             */
            'amortization_schedules_loan_id_status_due_date_index' => ['loan_id', 'status', 'due_date'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $indexes) {
                foreach ($indexes as $name => $columns) {
                    if (Schema::hasIndex($table, $name)) {
                        continue;
                    }

                    $blueprint->index($columns, $name);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $indexes) {
                foreach (array_keys($indexes) as $name) {
                    if (! Schema::hasIndex($table, $name)) {
                        continue;
                    }

                    $blueprint->dropIndex($name);
                }
            });
        }
    }
};
