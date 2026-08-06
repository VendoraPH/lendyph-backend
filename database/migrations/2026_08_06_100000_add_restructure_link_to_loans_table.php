<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link a restructured loan to the loan it was derived from.
     *
     * A restructure creates a NEW loan carrying the source loan's outstanding
     * balance. The source is only closed — status `restructured` — when that new
     * loan is RELEASED, so a rejected or voided restructure leaves the source
     * live and collectible.
     *
     * The column is `source_loan_id` because the frontend loan type already
     * declares `source_loan_id` + `is_restructure`.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->foreignId('source_loan_id')
                ->nullable()
                ->after('loan_product_id')
                ->constrained('loans')
                ->restrictOnDelete();

            $table->timestamp('restructured_at')->nullable();
            $table->decimal('restructured_balance', 12, 2)->nullable();
            $table->decimal('write_off_amount', 12, 2)->nullable();

            // Terms as approved, frozen at application time. The write-off is
            // computed from these at release, never from the live columns:
            // `principal_amount` is editable while the loan is a draft, and the
            // source's real balance keeps accruing penalties between approval
            // and release. Both would otherwise move the amount of debt being
            // destroyed after anyone signed off on it.
            $table->decimal('restructure_outstanding', 12, 2)->nullable();
            $table->decimal('restructure_principal', 12, 2)->nullable();
            $table->decimal('restructure_shortfall', 12, 2)->nullable();
            $table->text('restructure_remarks')->nullable();
        });

        $this->reopenLoansClosedByTheInPlaceRestructureBug();
    }

    /**
     * @throws RuntimeException when real restructure lineage exists
     */
    public function down(): void
    {
        // `source_loan_id` is the only thing marking a loan as legitimately
        // closed. Drop it and the repair step above — which spares loans that
        // have a child pointing at them — can no longer tell a genuinely
        // restructured loan from one damaged by the old in-place bug, so a
        // rollback followed by a re-migrate would reopen every closed source
        // and orphan its child. Refuse rather than corrupt.
        $linked = DB::table('loans')->whereNotNull('source_loan_id')->count();

        if ($linked > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$linked} loan(s) carry a source_loan_id. Dropping the column would "
                .'orphan those child loans and a re-migrate would reopen every legitimately restructured source. '
                .'Clear the restructure lineage deliberately first if this rollback is really intended.',
            );
        }

        Schema::table('loans', function (Blueprint $table) {
            $table->dropForeign(['source_loan_id']);
            $table->dropColumn([
                'source_loan_id', 'restructured_at', 'restructured_balance', 'write_off_amount',
                'restructure_outstanding', 'restructure_principal', 'restructure_shortfall', 'restructure_remarks',
            ]);
        });
    }

    /**
     * Repair loans that the in-place restructure adjustment left uncollectible.
     *
     * `LoanAdjustmentService::applyRestructure()` used to stamp
     * `status = 'restructured'` on a loan that was still owed, and
     * `RepaymentService::processRepayment()` only accepts `released`/`ongoing`
     * — so applying a restructure adjustment permanently blocked payments. That
     * assignment is removed in this change; this puts the already-damaged rows
     * back to `released`.
     *
     * Loans that legitimately reach `restructured` under the new semantics are
     * the ones whose balance moved to a child loan, so anything with a child
     * pointing at it is left alone. (No row can have one yet at this point in
     * the migration — the guard is here so a re-run stays correct.)
     */
    private function reopenLoansClosedByTheInPlaceRestructureBug(): void
    {
        // Selected first and updated by id rather than done in one statement:
        // MySQL rejects an UPDATE whose WHERE clause subqueries the table being
        // updated (error 1093), and both conditions here read `loans`.
        $childLoanIds = DB::table('loans')
            ->whereNotNull('source_loan_id')
            ->pluck('source_loan_id')
            ->all();

        $ids = DB::table('loans')
            ->where('status', 'restructured')
            ->whereIn('id', fn ($q) => $q->select('loan_id')
                ->from('loan_adjustments')
                ->where('adjustment_type', 'restructure')
                ->where('status', 'applied'))
            ->when($childLoanIds !== [], fn ($q) => $q->whereNotIn('id', $childLoanIds))
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return;
        }

        // A loan that had already taken a payment belongs at `ongoing`, not
        // `released` — that is the status `RepaymentService` moves it to on the
        // first posted repayment. Restoring them all to `released` would put
        // part-paid loans under the wrong status tab until their next payment
        // happened to correct it.
        $paidIds = DB::table('repayments')
            ->whereIn('loan_id', $ids)
            ->where('status', 'posted')
            ->distinct()
            ->pluck('loan_id')
            ->all();

        $unpaidIds = array_values(array_diff($ids, $paidIds));

        if ($paidIds !== []) {
            DB::table('loans')->whereIn('id', $paidIds)->update(['status' => 'ongoing']);
        }

        if ($unpaidIds !== []) {
            DB::table('loans')->whereIn('id', $unpaidIds)->update(['status' => 'released']);
        }
    }
};
