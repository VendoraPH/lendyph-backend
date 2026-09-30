<?php

use App\Models\Loan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Put the right value on share capital collateral that was attached at ₱0.
 *
 * Until 2026-09-30 (frontend #405) the browser summed a member's share capital
 * ledger from fields the API never sends, so every balance it computed was ₱0.
 * The new-loan and restructure collateral pickers send the value they show as
 * `snapshot_value` when they attach (POST /loans/{loan}/collaterals takes it
 * from the client), so a share capital collateral attached while that bug was
 * live was stored on its loan at ₱0. A restructure copies the source loan's
 * snapshot onto the new loan (LoanService::inheritCollaterals()), so the ₱0
 * travels with it.
 *
 * Each such attachment is set to what it should have said: the member's share
 * capital balance at the moment it was attached. That is the figure the
 * server's valuation (ShareCapitalLedger::balancesFor(), credits minus debits
 * over the whole ledger) returned at that moment, i.e. over the entries
 * recorded by then. A copy made by a restructure gets the corrected value of
 * the attachment it was copied from, because a copy carries the original
 * appraisal forward rather than re-valuing it.
 *
 * Only a value that can be stated with confidence is written. The balance over
 * the entries recorded by the attach must agree with the ledger's own reading
 * as of that day (entries dated on or before it). They disagree when an entry
 * was backdated past the attach, or dated that same day but recorded after it,
 * and then nobody can say which figure the operator saw. Such a row is left as
 * it is and listed in the log instead, as is an attachment with no
 * `attached_at`, one whose member has a ledger entry with no timestamp or
 * edited after the attach, a copy that no longer matches the attachment it came
 * from or whose source attachment was detached since (a re-attach is a new row,
 * attached after the copy was made, so it is not what was copied), and a
 * negative balance, which a snapshot cannot hold (the attach endpoint requires
 * 0 or more).
 *
 * A member whose balance really was ₱0 keeps ₱0. Every change is written to the
 * audit log as a system change (no user) against the loan. Running it again
 * changes nothing, because a corrected row is no longer ₱0.
 */
return new class extends Migration
{
    public const AUDIT_ACTION = 'collateral_snapshot_corrected';

    /**
     * Every share capital attachment stored at ₱0, with what this migration
     * decides for it.
     *
     * One statement, so the production dry run can execute exactly what the
     * migration executes. The `verdict` column is `change` (written, with
     * `new_value`), `unchanged_zero` (the balance really was ₱0) or
     * `undetermined` (left alone, with `undetermined_reason`).
     */
    private const PLAN_SQL = <<<'SQL'
        WITH RECURSIVE
        links AS (
            SELECT lc.id, lc.loan_id, lc.collateral_id, lc.snapshot_value, lc.attached_at,
                   c.borrower_id,
                   COALESCE(l.loan_account_number, l.application_number) AS loan_number,
                   l.source_loan_id,
                   EXISTS (
                       SELECT 1 FROM audit_logs a
                       WHERE a.action = 'restructure_created'
                         AND a.auditable_type = ?
                         AND a.auditable_id = lc.loan_id
                         AND JSON_CONTAINS(JSON_EXTRACT(a.new_values, '$.inherited_collateral_ids'), CAST(lc.collateral_id AS JSON))
                   ) AS is_copy
            FROM loan_collaterals lc
            JOIN collaterals c ON c.id = lc.collateral_id
            JOIN collateral_types ct ON ct.id = c.collateral_type_id
            JOIN loans l ON l.id = lc.loan_id
            WHERE ct.source = 'share_capital'
        ),
        copies AS (
            SELECT child.id AS link_id, parent.id AS parent_id
            FROM links child
            JOIN links parent ON parent.loan_id = child.source_loan_id
                             AND parent.collateral_id = child.collateral_id
                             AND parent.attached_at <= child.attached_at
            WHERE child.is_copy = 1
        ),
        lineage AS (
            SELECT links.id AS link_id, links.id AS origin_id, CAST(0 AS UNSIGNED) AS nonzero_ancestors
            FROM links
            WHERE links.id NOT IN (SELECT link_id FROM copies)
            UNION ALL
            SELECT copies.link_id, lineage.origin_id, lineage.nonzero_ancestors + (parent.snapshot_value <> 0)
            FROM copies
            JOIN lineage ON lineage.link_id = copies.parent_id
            JOIN links parent ON parent.id = copies.parent_id
        ),
        valuations AS (
            SELECT links.id AS origin_id,
                   (SELECT COALESCE(SUM(s.credit - s.debit), 0) FROM share_capital_ledger s
                     WHERE s.borrower_id = links.borrower_id AND s.created_at <= links.attached_at) AS recorded_balance,
                   (SELECT COALESCE(SUM(s.credit - s.debit), 0) FROM share_capital_ledger s
                     WHERE s.borrower_id = links.borrower_id AND s.date <= DATE(links.attached_at)) AS dated_balance,
                   (SELECT COUNT(*) FROM share_capital_ledger s
                     WHERE s.borrower_id = links.borrower_id AND s.created_at IS NULL) AS untimed_entries,
                   (SELECT COUNT(*) FROM share_capital_ledger s
                     WHERE s.borrower_id = links.borrower_id
                       AND s.updated_at > s.created_at AND s.updated_at > links.attached_at) AS edited_entries
            FROM links
        ),
        planned AS (
            SELECT cand.id AS loan_collateral_id, cand.loan_id, cand.loan_number, cand.collateral_id,
                   cand.borrower_id, cand.attached_at, cand.snapshot_value AS old_value,
                   lineage.origin_id AS valued_from_loan_collateral_id,
                   origin.loan_number AS valued_from_loan, origin.attached_at AS valued_as_of,
                   v.recorded_balance, v.dated_balance,
                   CASE
                       WHEN origin.attached_at IS NULL THEN 'attached_at is unknown'
                       WHEN origin.is_copy = 1 THEN 'copied from an attachment that no longer exists'
                       WHEN lineage.nonzero_ancestors > 0 THEN 'no longer matches the attachment it was copied from'
                       WHEN v.untimed_entries > 0 THEN 'a ledger entry of this member has no timestamp'
                       WHEN v.edited_entries > 0 THEN 'a ledger entry of this member was edited after the attach'
                       WHEN v.recorded_balance <> v.dated_balance THEN 'a ledger entry is dated on the other side of the attach from when it was recorded'
                       WHEN v.recorded_balance < 0 THEN 'the balance was negative'
                   END AS undetermined_reason
            FROM links cand
            JOIN lineage ON lineage.link_id = cand.id
            JOIN links origin ON origin.id = lineage.origin_id
            JOIN valuations v ON v.origin_id = lineage.origin_id
            WHERE cand.snapshot_value = 0
        )
        SELECT planned.*,
               CASE
                   WHEN undetermined_reason IS NOT NULL THEN 'undetermined'
                   WHEN recorded_balance = 0 THEN 'unchanged_zero'
                   ELSE 'change'
               END AS verdict,
               CASE
                   WHEN undetermined_reason IS NULL AND recorded_balance <> 0 THEN recorded_balance
               END AS new_value
        FROM planned
        ORDER BY loan_collateral_id
        SQL;

    public function up(): void
    {
        $plan = $this->plan();
        $changes = array_values(array_filter($plan, fn (object $row): bool => $row->verdict === 'change'));
        $undetermined = array_values(array_filter($plan, fn (object $row): bool => $row->verdict === 'undetermined'));

        $corrected = DB::transaction(function () use ($changes): int {
            $corrected = 0;

            foreach ($changes as $row) {
                // Guarded on the stored ₱0, so a row somebody corrected by hand
                // between the plan and the write is left as they set it.
                $updated = DB::table('loan_collaterals')
                    ->where('id', $row->loan_collateral_id)
                    ->where('snapshot_value', 0)
                    ->update(['snapshot_value' => $row->new_value, 'updated_at' => now()]);

                if ($updated === 0) {
                    continue;
                }

                DB::table('audit_logs')->insert([
                    'user_id' => null,
                    'action' => self::AUDIT_ACTION,
                    'auditable_type' => (new Loan)->getMorphClass(),
                    'auditable_id' => $row->loan_id,
                    'old_values' => json_encode([
                        'loan_collateral_id' => (int) $row->loan_collateral_id,
                        'collateral_id' => (int) $row->collateral_id,
                        'snapshot_value' => (float) $row->old_value,
                    ]),
                    'new_values' => json_encode([
                        'loan_collateral_id' => (int) $row->loan_collateral_id,
                        'collateral_id' => (int) $row->collateral_id,
                        'snapshot_value' => (float) $row->new_value,
                        'valued_as_of' => $row->valued_as_of,
                        'valued_from_loan_collateral_id' => (int) $row->valued_from_loan_collateral_id,
                    ]),
                    'ip_address' => null,
                    'user_agent' => null,
                    'description' => $this->describe($row),
                    'created_at' => now(),
                ]);

                $corrected++;
            }

            return $corrected;
        });

        Log::info('Corrected share capital collateral stored at ₱0.', [
            'corrected' => $corrected,
            'unchanged_zero' => count($plan) - count($changes) - count($undetermined),
            'undetermined' => count($undetermined),
        ]);

        if ($undetermined !== []) {
            Log::warning('Share capital collateral stored at ₱0 whose value could not be determined was left unchanged.', [
                'rows' => array_map(fn (object $row): array => [
                    'loan_collateral_id' => (int) $row->loan_collateral_id,
                    'loan' => $row->loan_number,
                    'collateral_id' => (int) $row->collateral_id,
                    'reason' => $row->undetermined_reason,
                ], $undetermined),
            ]);
        }
    }

    /**
     * Nothing to undo. Putting the ₱0 back would restore the bug's value, and
     * each change is on record in the audit log.
     */
    public function down(): void {}

    /**
     * What up() would do, without writing anything.
     *
     * @return array<int, object>
     */
    public function plan(): array
    {
        return DB::select(self::PLAN_SQL, $this->planBindings());
    }

    /**
     * The plan as one runnable statement with its binding filled in, for a
     * read-only dry run against a database that has not run this migration.
     */
    public function planSql(): string
    {
        return DB::connection()->getQueryGrammar()->substituteBindingsIntoRawSql(self::PLAN_SQL, $this->planBindings());
    }

    /**
     * The audit description. A restructure copy names the attachment its value
     * came from, since it was valued as of that one's attach, not its own.
     */
    private function describe(object $row): string
    {
        $source = (int) $row->valued_from_loan_collateral_id === (int) $row->loan_collateral_id
            ? 'when it was attached'
            : "when it was attached to loan {$row->valued_from_loan}, which this restructure carried it over from";

        return sprintf(
            'System correction: share capital collateral #%d on loan %s was stored at ₱0 by a valuation bug. '
            .'Set to ₱%s, the member\'s share capital balance %s (%s).',
            $row->collateral_id,
            $row->loan_number,
            number_format((float) $row->new_value, 2),
            $source,
            $row->valued_as_of,
        );
    }

    /**
     * @return array<int, string>
     */
    private function planBindings(): array
    {
        return [(new Loan)->getMorphClass()];
    }
};
