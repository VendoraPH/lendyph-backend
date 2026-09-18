<?php

namespace App\Services\Accounting;

use App\Models\AccountingJournal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Where the cash came from and where it went, by direct observation.
 *
 * ## The method
 *
 * Every journal that touches a money account is an explanation of a cash
 * movement, and the OTHER lines in that entry are the explanation. Collect a
 * loan payment — Dr Cash 5,000, Cr Interest Income 5,000 — and the income line
 * says what the 5,000 was. So for each such entry, every non-cash line
 * contributes `credit - debit` to cash, and it is filed under that account's
 * `cash_flow_category`.
 *
 * This is the direct method, and it is chosen over the indirect one for a
 * reason beyond taste: the indirect method starts from net income and works
 * backwards through changes in working capital, which means every figure on it
 * is a difference between two balances and none of them can be traced to a
 * transaction. This one can. Every peso on the statement came off a journal
 * line that is still there to look at.
 *
 * ## A sweep between two of our own accounts contributes nothing
 *
 * Dr Bank, Cr GCash has no non-cash line at all, so it contributes nothing to
 * any section — which is right, because moving money between two pockets is not
 * a source or a use of it. That falls out of the method rather than being
 * special-cased, which is why `cash` exists as a category.
 *
 * ## The statement proves itself
 *
 * `net_change` is computed independently, from the cash accounts' own opening
 * and closing balances, and then compared against the three sections. They must
 * agree, and by construction they do — a balanced entry cannot have non-cash
 * lines that fail to account for its cash lines. `is_reconciled` and
 * `unexplained` are emitted anyway, because the day that stops being true is
 * the day someone needs to know rather than be shown a tidy report.
 *
 * MONEY IS IN CENTAVOS.
 */
class CashFlowStatementBuilder
{
    public function __construct(private TrialBalanceBuilder $trialBalance) {}

    /**
     * @return array<string, mixed> `CashFlowStatement` in src/types/accounting.ts
     */
    public function build(string $from, string $to, ?int $branchId = null): array
    {
        $cashAccountIds = $this->cashAccountIds();

        // The day before the period starts. Opening cash is the closing cash of
        // the previous day, not of the first day of the period — including the
        // first day's movements in the opening balance would net them out of
        // the statement entirely.
        $dayBefore = CarbonImmutable::parse($from)->subDay()->toDateString();

        $openingCash = $this->cashTotal($cashAccountIds, $dayBefore, $branchId);
        $closingCash = $this->cashTotal($cashAccountIds, $to, $branchId);
        $netChange = $closingCash - $openingCash;

        $lines = $this->explanations($cashAccountIds, $from, $to, $branchId);

        $sections = [];
        $sectionTotal = 0;

        foreach (CashFlowCategories::ACTIVITIES as $activity) {
            $section = $this->section($activity, $lines);
            $sections[$activity] = $section;
            $sectionTotal += $section['total'];
        }

        return [
            'from' => $from,
            'to' => $to,
            'operating' => $sections['operating'],
            'investing' => $sections['investing'],
            'financing' => $sections['financing'],
            // From the balances, not from the sections. Two independent routes
            // to the same number is the only thing that makes the check below
            // mean anything.
            'net_change' => $netChange,
            'opening_cash' => $openingCash,
            'closing_cash' => $closingCash,
            // Beyond the TypeScript interface, and deliberately. If the
            // sections ever stop adding up to the movement in cash, that is a
            // fact about the books that has to be visible rather than a
            // difference quietly absorbed into a subtotal.
            'is_reconciled' => $sectionTotal === $netChange,
            'unexplained' => $netChange - $sectionTotal,
        ];
    }

    /**
     * The accounts the statement is about.
     *
     * Defined by `cash_kind`, NOT by `cash_flow_category = 'cash'`. `cash_kind`
     * is already what puts an account on the Cash & Bank screen and into the
     * dashboard's cash figures, and having two definitions of "what counts as
     * cash" is how the cash flow statement and the dashboard come to disagree
     * about the same organisation on the same day. The category is editable;
     * this must not be.
     *
     * @return list<int>
     */
    private function cashAccountIds(): array
    {
        return DB::table('accounting_accounts')
            ->whereNotNull('cash_kind')
            ->where('is_group', false)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Total cash held as of a date, in centavos.
     *
     * Through TrialBalanceBuilder, so this figure and the Cash & Bank screen's
     * "Total across all money accounts" are the same arithmetic over the same
     * rows. Two aggregates would eventually disagree over a reversal, and both
     * would look right.
     *
     * @param  list<int>  $cashAccountIds
     */
    private function cashTotal(array $cashAccountIds, string $asOf, ?int $branchId): int
    {
        if ($cashAccountIds === []) {
            return 0;
        }

        return Money::sum(
            array_values($this->trialBalance->signedBalances($asOf, $branchId, $cashAccountIds))
        );
    }

    /**
     * One row per non-cash account, summing what it explained about cash.
     *
     * ONE query. The naive version — walk the journals, then their lines —
     * is a round trip per entry, and a co-op with a year of collections has
     * tens of thousands of them.
     *
     * The subquery picks the journals that touched cash; the outer query sums
     * the non-cash lines of exactly those journals. `HISTORICAL_STATUSES`
     * counts posted AND reversed, because a reversal is itself a pair of lines
     * and dropping the reversed original would leave the reversal explaining a
     * movement that no longer appears.
     *
     * @param  list<int>  $cashAccountIds
     * @return list<array<string, mixed>>
     */
    private function explanations(array $cashAccountIds, string $from, string $to, ?int $branchId): array
    {
        if ($cashAccountIds === []) {
            return [];
        }

        $touchedCash = DB::table('accounting_journal_lines')
            ->select('accounting_journal_id')
            ->whereIn('accounting_account_id', $cashAccountIds);

        return DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->join('accounting_accounts as a', 'a.id', '=', 'l.accounting_account_id')
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            // Plain comparisons on a DATE column so its index stays usable.
            ->where('j.date', '>=', $from)
            ->where('j.date', '<=', $to)
            ->when($branchId !== null, fn ($q) => $q->where('j.branch_id', $branchId))
            ->whereIn('l.accounting_journal_id', $touchedCash)
            // The cash lines are the movement, not the explanation of it.
            ->whereNotIn('l.accounting_account_id', $cashAccountIds)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.cash_flow_category')
            ->orderBy('a.code')
            ->select([
                'a.id as account_id',
                'a.code as account_code',
                'a.name as account_name',
                'a.cash_flow_category',
                DB::raw('sum(l.credit) as credits'),
                DB::raw('sum(l.debit) as debits'),
            ])
            ->get()
            ->map(static fn (object $row): array => [
                'account_id' => (int) $row->account_id,
                'account_code' => (string) $row->account_code,
                'account_name' => (string) $row->account_name,
                // An account with no classification is filed under operating
                // rather than dropped. Dropping it would take its amount off
                // the statement while leaving it in `net_change`, and the
                // difference would surface as `unexplained` with nothing
                // naming the account responsible. The saving hook on
                // AccountingAccount means this should be unreachable.
                'category' => CashFlowCategories::isActivity($row->cash_flow_category)
                    ? (string) $row->cash_flow_category
                    : 'operating',
                // The cash contribution. A credit to Interest Income is cash
                // coming IN; a debit to Loans Receivable is cash going OUT.
                // MySQL returns SUM() as a DECIMAL string, so both are cast.
                'amount' => (int) $row->credits - (int) $row->debits,
            ])
            ->all();
    }

    /**
     * One section, with a line per account.
     *
     * A line per account rather than a single subtotal is the whole point.
     * "Investing activities ₱(2,400,000)" tells a reader nothing about whether
     * the classification was right; "1110 Loans Receivable ₱(2,400,000)" under
     * the same heading lets them see immediately that loan releases are being
     * treated as investing, and go and change it if they disagree.
     *
     * Accounts that netted to zero are dropped — they explain nothing about
     * cash — but a zero-total SECTION is kept, because "no financing activity
     * this period" is information and an absent heading looks like a bug.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{label: string, lines: list<array<string, mixed>>, total: int}
     */
    private function section(string $activity, array $lines): array
    {
        $rows = [];

        foreach ($lines as $line) {
            if ($line['category'] !== $activity || $line['amount'] === 0) {
                continue;
            }

            $rows[] = [
                'account_id' => $line['account_id'],
                'account_code' => $line['account_code'],
                // `label` is what the report renders beside the code. The
                // account's own name, so the classification it was filed under
                // is readable at a glance.
                'label' => $line['account_name'],
                'amount' => $line['amount'],
            ];
        }

        return [
            'label' => CashFlowCategories::SECTION_LABELS[$activity],
            'lines' => $rows,
            'total' => Money::sum(array_column($rows, 'amount')),
        ];
    }
}
