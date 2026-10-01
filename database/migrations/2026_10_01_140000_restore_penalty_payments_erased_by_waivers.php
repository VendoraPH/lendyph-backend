<?php

use App\Models\Loan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Put back penalty payments that a penalty waiver erased from the periods.
 *
 * Until 2026-10-01 LoanAdjustmentService::applyPenaltyWaiver() set both
 * `penalty_amount` and `penalty_paid` to 0 on every open period it covered. So
 * penalty a borrower had already paid on such a period vanished from the
 * period records while the receipt (`repayments.penalty_applied`) still
 * carried it, and Total Paid dropped by that amount. The fix keeps
 * `penalty_paid`; this restores what the old code erased.
 *
 * A receipt records its penalty for the loan, not per period, so the period
 * comes from the order payments are applied in. RepaymentService takes periods
 * strictly by period number and settles one in full (penalty, interest,
 * principal) before moving to the next, so when the waiver ran only one open
 * period could hold paid penalty: the first one not yet settled. Every period
 * before it was settled and has absorbed, ever since, exactly its principal,
 * its interest and its `penalty_paid`; nothing after it had received a peso.
 * Walking the periods in order and spending what the receipts recorded before
 * the waiver, less the missing penalty, the first period that this money does
 * not fully cover is the one the waiver erased. That period was still open, so
 * its principal and interest were not fully paid, which is what makes the
 * walk stop there and not one period early or late.
 *
 * The missing amount is what the receipts carry and the periods do not: posted
 * `penalty_applied` minus `penalty_paid`, over the loan. It goes back on that
 * period, and the period's penalty charge is raised to it where it is lower,
 * because the old waiver lowered the charge along with the payment. Restoring
 * it cannot change the period's status: an open period is still short on
 * principal or interest, and a settled one stays settled.
 *
 * Only a value that can be stated with confidence is written. That rests on
 * the payment order above, so a loan is left as it is and listed in the log
 * when anything else has rewritten its periods: another applied adjustment
 * (a second waiver, an extension, a restructure, a term extension or a balance
 * adjustment), a voided payment, a closing restructure, or receipts that do not
 * match the periods on principal or interest. The same goes for a receipt
 * recorded in the waiver's exact second, more penalty missing than was paid
 * before the waiver, and a period outside the waiver's selection.
 *
 * Every change is written to the audit log as a system change (no user)
 * against the loan. Running it again changes nothing, because once a period
 * holds its penalty again the receipts and the periods agree.
 */
return new class extends Migration
{
    public const AUDIT_ACTION = 'penalty_payment_restored';

    /**
     * Every loan whose latest applied penalty waiver left the periods holding
     * a different penalty total from the posted receipts, with what this
     * migration decides for it.
     *
     * One statement, so a read-only dry run can execute exactly what the
     * migration executes. The `verdict` column is `change` (written) or
     * `undetermined` (left alone, with `undetermined_reason`).
     */
    private const PLAN_SQL = <<<'SQL'
        WITH
        waivers AS (
            SELECT a.id AS adjustment_id, a.adjustment_number, a.loan_id, a.applied_at, a.new_values,
                   ROW_NUMBER() OVER (PARTITION BY a.loan_id ORDER BY a.applied_at DESC, a.id DESC) AS latest
            FROM loan_adjustments a
            WHERE a.adjustment_type = 'penalty_waiver' AND a.status = 'applied'
        ),
        loan_facts AS (
            SELECT w.adjustment_id, w.adjustment_number, w.loan_id, w.applied_at, w.new_values,
                   COALESCE(l.loan_account_number, l.application_number) AS loan_number,
                   l.status AS loan_status,
                   (SELECT COUNT(*) FROM loan_adjustments o
                     WHERE o.loan_id = w.loan_id AND o.status = 'applied') AS applied_adjustments,
                   (SELECT COUNT(*) FROM repayments r
                     WHERE r.loan_id = w.loan_id AND r.status = 'voided') AS voided_payments,
                   (SELECT COUNT(*) FROM repayments r
                     WHERE r.loan_id = w.loan_id AND r.created_at = w.applied_at) AS same_second_payments,
                   (SELECT COALESCE(SUM(r.principal_applied), 0) FROM repayments r
                     WHERE r.loan_id = w.loan_id AND r.status = 'posted') AS principal_applied,
                   (SELECT COALESCE(SUM(r.interest_applied), 0) FROM repayments r
                     WHERE r.loan_id = w.loan_id AND r.status = 'posted') AS interest_applied,
                   (SELECT COALESCE(SUM(r.penalty_applied), 0) FROM repayments r
                     WHERE r.loan_id = w.loan_id AND r.status = 'posted') AS penalty_applied,
                   (SELECT COALESCE(SUM(r.penalty_applied), 0) FROM repayments r
                     WHERE r.loan_id = w.loan_id AND r.status = 'posted'
                       AND r.created_at < w.applied_at) AS penalty_applied_before_waiver,
                   (SELECT COALESCE(SUM(r.principal_applied + r.interest_applied + r.penalty_applied), 0) FROM repayments r
                     WHERE r.loan_id = w.loan_id AND r.status = 'posted'
                       AND r.created_at < w.applied_at) AS applied_before_waiver,
                   (SELECT COALESCE(SUM(s.principal_paid), 0) FROM amortization_schedules s
                     WHERE s.loan_id = w.loan_id) AS principal_paid,
                   (SELECT COALESCE(SUM(s.interest_paid), 0) FROM amortization_schedules s
                     WHERE s.loan_id = w.loan_id) AS interest_paid,
                   (SELECT COALESCE(SUM(s.penalty_paid), 0) FROM amortization_schedules s
                     WHERE s.loan_id = w.loan_id) AS penalty_paid
            FROM waivers w
            JOIN loans l ON l.id = w.loan_id
            WHERE w.latest = 1
        ),
        gaps AS (
            SELECT loan_facts.*, penalty_applied - penalty_paid AS missing
            FROM loan_facts
            WHERE penalty_applied <> penalty_paid
        ),
        periods AS (
            SELECT s.id AS schedule_id, s.loan_id, s.period_number, s.penalty_amount, s.penalty_paid,
                   s.principal_due + s.interest_due + s.penalty_paid AS absorbed,
                   COALESCE(SUM(s.principal_due + s.interest_due + s.penalty_paid) OVER (
                       PARTITION BY s.loan_id ORDER BY s.period_number
                       ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING
                   ), 0) AS absorbed_before
            FROM amortization_schedules s
            WHERE s.loan_id IN (SELECT loan_id FROM gaps)
        ),
        erased AS (
            SELECT periods.*,
                   gaps.applied_before_waiver - gaps.missing - periods.absorbed_before AS reached_period,
                   ROW_NUMBER() OVER (PARTITION BY periods.loan_id ORDER BY periods.period_number) AS first_short
            FROM periods
            JOIN gaps ON gaps.loan_id = periods.loan_id
            WHERE gaps.applied_before_waiver - gaps.missing - periods.absorbed_before < periods.absorbed
        ),
        planned AS (
            SELECT gaps.loan_id, gaps.loan_number, gaps.adjustment_id, gaps.adjustment_number, gaps.applied_at,
                   gaps.missing, erased.schedule_id, erased.period_number,
                   erased.penalty_amount AS old_penalty_amount, erased.penalty_paid AS old_penalty_paid,
                   CASE
                       WHEN gaps.applied_adjustments > 1 THEN 'another adjustment was also applied to the loan'
                       WHEN gaps.voided_payments > 0 THEN 'the loan has a voided payment'
                       WHEN gaps.loan_status = 'restructured' THEN 'the loan was closed by a restructure'
                       WHEN gaps.principal_applied <> gaps.principal_paid OR gaps.interest_applied <> gaps.interest_paid
                           THEN 'the receipts and the periods disagree on principal or interest'
                       WHEN gaps.missing < 0 THEN 'the periods hold more penalty than the receipts'
                       WHEN gaps.same_second_payments > 0 THEN 'a payment was recorded in the same second the waiver was applied'
                       WHEN gaps.missing > gaps.penalty_applied_before_waiver THEN 'more penalty is missing than was paid before the waiver'
                       WHEN erased.schedule_id IS NULL OR erased.reached_period < 0 THEN 'no period matches the receipts'
                       WHEN NOT (
                           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(gaps.new_values, '$.waive_all')) IN ('true', '1'), FALSE)
                           OR COALESCE(JSON_CONTAINS(JSON_EXTRACT(gaps.new_values, '$.schedule_ids'), CAST(erased.schedule_id AS JSON)), FALSE)
                           OR COALESCE(JSON_CONTAINS(JSON_EXTRACT(gaps.new_values, '$.schedule_ids'), JSON_QUOTE(CAST(erased.schedule_id AS CHAR))), FALSE)
                       ) THEN 'the matching period was not covered by the waiver'
                   END AS undetermined_reason
            FROM gaps
            LEFT JOIN erased ON erased.loan_id = gaps.loan_id AND erased.first_short = 1
        )
        SELECT planned.*,
               CASE WHEN undetermined_reason IS NULL THEN 'change' ELSE 'undetermined' END AS verdict,
               CASE WHEN undetermined_reason IS NULL THEN old_penalty_paid + missing END AS new_penalty_paid,
               CASE WHEN undetermined_reason IS NULL THEN GREATEST(old_penalty_amount, old_penalty_paid + missing) END AS new_penalty_amount
        FROM planned
        ORDER BY loan_id
        SQL;

    public function up(): void
    {
        $plan = $this->plan();
        $changes = array_values(array_filter($plan, fn (object $row): bool => $row->verdict === 'change'));
        $undetermined = array_values(array_filter($plan, fn (object $row): bool => $row->verdict === 'undetermined'));

        $restored = DB::transaction(function () use ($changes): int {
            $restored = 0;

            foreach ($changes as $row) {
                // Guarded on the values the plan read, so a period that changed
                // between the plan and the write is left as it now is.
                $updated = DB::table('amortization_schedules')
                    ->where('id', $row->schedule_id)
                    ->where('penalty_paid', $row->old_penalty_paid)
                    ->where('penalty_amount', $row->old_penalty_amount)
                    ->update([
                        'penalty_paid' => $row->new_penalty_paid,
                        'penalty_amount' => $row->new_penalty_amount,
                        'updated_at' => now(),
                    ]);

                if ($updated === 0) {
                    continue;
                }

                DB::table('audit_logs')->insert([
                    'user_id' => null,
                    'action' => self::AUDIT_ACTION,
                    'auditable_type' => (new Loan)->getMorphClass(),
                    'auditable_id' => $row->loan_id,
                    'old_values' => json_encode([
                        'schedule_id' => (int) $row->schedule_id,
                        'period_number' => (int) $row->period_number,
                        'penalty_amount' => (float) $row->old_penalty_amount,
                        'penalty_paid' => (float) $row->old_penalty_paid,
                    ]),
                    'new_values' => json_encode([
                        'schedule_id' => (int) $row->schedule_id,
                        'period_number' => (int) $row->period_number,
                        'penalty_amount' => (float) $row->new_penalty_amount,
                        'penalty_paid' => (float) $row->new_penalty_paid,
                        'restored' => (float) $row->missing,
                        'waiver' => $row->adjustment_number,
                    ]),
                    'ip_address' => null,
                    'user_agent' => null,
                    'description' => $this->describe($row),
                    'created_at' => now(),
                ]);

                $restored++;
            }

            return $restored;
        });

        Log::info('Restored penalty payments erased by penalty waivers.', [
            'restored' => $restored,
            'undetermined' => count($undetermined),
        ]);

        if ($undetermined !== []) {
            Log::warning('Penalty payments erased by a waiver that could not be placed on a period were left unchanged.', [
                'rows' => array_map(fn (object $row): array => [
                    'loan' => $row->loan_number,
                    'waiver' => $row->adjustment_number,
                    'missing' => (float) $row->missing,
                    'reason' => $row->undetermined_reason,
                ], $undetermined),
            ]);
        }
    }

    /**
     * Nothing to undo. Erasing the payments again would restore the bug's
     * state, and each change is on record in the audit log.
     */
    public function down(): void {}

    /**
     * What up() would do, without writing anything.
     *
     * @return array<int, object>
     */
    public function plan(): array
    {
        return DB::select(self::PLAN_SQL);
    }

    /**
     * The plan as one runnable statement, for a read-only dry run against a
     * database that has not run this migration.
     */
    public function planSql(): string
    {
        return self::PLAN_SQL;
    }

    private function describe(object $row): string
    {
        return sprintf(
            'System correction: penalty waiver %s (applied %s) erased ₱%s of penalty already paid on period %d of loan %s. '
            .'Restored from the loan\'s payment records.',
            $row->adjustment_number,
            $row->applied_at,
            number_format((float) $row->missing, 2),
            $row->period_number,
            $row->loan_number,
        );
    }
};
