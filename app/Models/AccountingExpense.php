<?php

namespace App\Models;

use App\Services\BranchAccess;
use App\Services\LikePattern;
use App\Support\StaffRouteBinding;
use App\Traits\Auditable;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An operating cost, paid on the spot or owed.
 *
 * Amounts are INTEGER CENTAVOS throughout — see the migration for why.
 *
 * @property int $id
 * @property string $date
 * @property string $payee
 * @property int $expense_account_id
 * @property int $amount
 * @property int $amount_paid
 * @property int|null $payment_account_id
 * @property int|null $branch_id
 * @property string|null $reference
 * @property string|null $description
 * @property string|null $due_date
 * @property string $settlement_status
 * @property int|null $journal_id
 * @property int|null $created_by
 */
class AccountingExpense extends Model
{
    /**
     * Who recorded a cost, who changed its payee, who set a due date.
     *
     * The financial fields cannot be edited at all once the journal is posted
     * (see AccountingExpenseController::update), so what this trail records is
     * the descriptive drift — which is exactly the part no journal captures.
     */
    use Auditable, HasFactory;

    protected $table = 'accounting_expenses';

    /**
     * The five statuses `ExpenseStatus` in src/types/accounting.ts carries.
     *
     * `overdue` and `reversed` are in this list and NOT in the column's enum on
     * purpose: both are derived on read by {@see self::status()}. See
     * SETTLEMENT_STATUSES for the three values that are actually stored.
     */
    public const STATUSES = ['unpaid', 'partially_paid', 'paid', 'overdue', 'reversed'];

    /** The stored settlement state. `overdue` and `reversed` are never one of these. */
    public const SETTLEMENT_STATUSES = ['unpaid', 'partially_paid', 'paid'];

    /**
     * The longest chain of reversals {@see self::endsReversed()} and its SQL
     * twin follow: far beyond any real one, so a damaged link can't loop.
     */
    public const MAX_REVERSALS = 100;

    /**
     * The relations status() and amountDue() read, for a list to eager-load:
     * the journal and the first two entries in its chain of reversals. A
     * longer chain loads the rest as it's read.
     */
    public const STATUS_RELATIONS = [
        'journal:id,status,reversed_by_journal_id',
        'journal.reversedBy:id,status,reversed_by_journal_id',
        'journal.reversedBy.reversedBy:id,status,reversed_by_journal_id',
    ];

    protected $fillable = [
        'date',
        'payee',
        'expense_account_id',
        'amount',
        'amount_paid',
        'payment_account_id',
        'branch_id',
        'reference',
        'description',
        'due_date',
        'settlement_status',
        'journal_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            // `date:Y-m-d`, not `date`. A plain `date` cast serialises to JSON
            // as an ISO-8601 INSTANT ("2026-09-18T00:00:00.000000Z"), and
            // `formatDate()` on the Expenses screen would render it through a
            // timezone that moves it a day. These are calendar dates and must
            // travel as calendar dates.
            'date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            // INTEGER centavos. Without these casts MySQL hands BIGINT back as
            // a PHP string and the resource would emit `"1500050"`, which the
            // frontend's `sumCentavos` had to be hardened against once already.
            'amount' => 'integer',
            'amount_paid' => 'integer',
            'expense_account_id' => 'integer',
            'payment_account_id' => 'integer',
            'branch_id' => 'integer',
            'journal_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'expense_account_id');
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'payment_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'journal_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AccountingExpensePayment::class, 'accounting_expense_id');
    }

    /** What is still owed, in centavos. Never negative — the CHECK sees to it. */
    public function outstanding(): int
    {
        return $this->amount - $this->amount_paid;
    }

    /**
     * What the Expenses list shows as still owed, in centavos: the outstanding
     * amount, or nothing while the expense's journal stands reversed, because
     * the reversal undid the payable an accrual took on. The same figure as
     * the list's `meta.totals.amount_due`, which leaves reversed expenses out.
     */
    public function amountDue(): int
    {
        return $this->isReversed() ? 0 : $this->outstanding();
    }

    /**
     * Whether the expense's journal stands reversed on the Journals screen.
     * The reversal undid the cost, and an accrual's payable, in the books,
     * while the row and its settlement column stay as they were. Reversing
     * that reversal puts the cost back, so the expense counts again, and so on
     * along the chain (self::endsReversed()). An expense with no journal isn't
     * reversed.
     *
     * {@see self::scopeCounted()} and {@see self::scopeReversed()} are its SQL
     * twins. Reads the `journal` relation and its reversals; a list
     * eager-loads self::STATUS_RELATIONS.
     */
    public function isReversed(): bool
    {
        return self::endsReversed(
            $this->journal_id === null ? null : $this->journal,
            static fn (AccountingJournal $journal): ?AccountingJournal => $journal->reversedBy,
        );
    }

    /**
     * Whether the chain of reversals that starts at `$journal` ends on a
     * reversal, so the entry stands undone in the books.
     *
     * JournalPoster::reverse() marks the entry it reverses `reversed` and links
     * it to the reversal (`reversed_by_journal_id`); the reversal is posted,
     * and can be reversed the same way. So an entry reversed once, three
     * times, … stands undone, and one reversed twice, four times, … stands
     * again. `$reversalOf` reads the next entry: the relation, or the row
     * under a lock (ExpenseRecorder::pay()).
     *
     * @param  Closure(AccountingJournal): ?AccountingJournal  $reversalOf
     */
    public static function endsReversed(?AccountingJournal $journal, Closure $reversalOf): bool
    {
        $reversed = false;

        for ($reversals = 0; $reversals < self::MAX_REVERSALS && $journal !== null
            && $journal->status === 'reversed' && $journal->reversed_by_journal_id !== null; $reversals++) {
            $reversed = ! $reversed;
            $journal = $reversalOf($journal);
        }

        return $reversed;
    }

    /** True when this was paid on the spot, so there is no payable to settle. */
    public function wasPaidOnTheSpot(): bool
    {
        return $this->payment_account_id !== null;
    }

    /**
     * The status the frontend renders, derived rather than stored.
     *
     * The precedence is deliberate and the one judgement call in this file:
     * a partially paid bill that is past its due date comes back `overdue`, not
     * `partially_paid`. `ExpenseStatus` is single-valued and the Expenses
     * screen filters on exactly this string, so one of the two facts has to
     * win — and the one worth acting on is that the money is late. Reporting it
     * as `partially_paid` would drop it out of the Overdue filter, which is the
     * one view whose entire purpose is to be complete.
     *
     * `reversed` comes first, whatever the settlement column says: an expense
     * whose journal stands reversed on the Journals screen cost nothing and
     * owes nothing in the books, so it counts in no total, count or other
     * status (finish run round 4). Once that reversal is itself reversed it
     * reads its settlement status again. The branch closure checklist reads
     * the same rule.
     *
     * {@see self::scopeWithStatus()} is the SQL twin of this ladder, and
     * AccountingExpenseListTest and ReversedExpenseTest assert the two agree
     * row for row.
     */
    public function status(): string
    {
        if ($this->isReversed()) {
            return 'reversed';
        }

        if ($this->amount_paid >= $this->amount) {
            return 'paid';
        }

        if ($this->isOverdue()) {
            return 'overdue';
        }

        return $this->amount_paid > 0 ? 'partially_paid' : 'unpaid';
    }

    /**
     * Past its due date with money still outstanding.
     *
     * "Today" is Philippine local, because `date` and `due_date` are Philippine
     * calendar dates. Deriving it from a UTC instant would make everything due
     * today read as overdue for the first eight hours of every Manila morning.
     */
    public function isOverdue(): bool
    {
        if ($this->due_date === null || $this->amount_paid >= $this->amount) {
            return false;
        }

        return $this->due_date->toDateString() < self::today();
    }

    /** Today, as a Philippine calendar date. */
    public static function today(): string
    {
        return CarbonImmutable::now()->toDateString();
    }

    /**
     * Newest first, with `id` as the tiebreaker.
     *
     * The tiebreaker is not cosmetic. The Expenses screen DRAINS this list page
     * by page, and `order by date desc` alone is a PARTIAL order: MySQL may
     * return two same-day rows in one order for page 1 and the other order for
     * page 2, so a row can be served twice and another never at all. The
     * headline "Outstanding" total is a sum over what came back, so the symptom
     * is a plausible figure that is simply wrong.
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('date')->orderByDesc('id');
    }

    /**
     * Rows in the branches $user may see (BranchAccess). A row with no branch
     * is visible only to `branches:view_all`.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $branchIds = app(BranchAccess::class)->for($user);

        return $branchIds === null ? $query : $query->whereIn('branch_id', $branchIds);
    }

    /**
     * The Expenses & Payables list's `meta.totals`: `{count, amount,
     * amount_paid, amount_due}` over every expense $matching selects that
     * counts ({@see self::scopeCounted()}), in one aggregate. An expense whose
     * journal stands reversed counts in none of them, under the Reversed
     * filter too. Integer centavos; `amount_due` is `amount - amount_paid` summed in
     * SQL, the same figure as each row's `amount_due`. Linda's expense answer
     * reads the same totals.
     *
     * @param  Builder<AccountingExpense>  $matching
     * @return array{count: int, amount: int, amount_paid: int, amount_due: int}
     */
    public static function listTotals(Builder $matching): array
    {
        $row = $matching
            ->counted()
            ->toBase()
            ->selectRaw('COUNT(*) as row_count, COALESCE(SUM(amount), 0) as amount, COALESCE(SUM(amount_paid), 0) as amount_paid, COALESCE(SUM(amount - amount_paid), 0) as amount_due')
            ->first();

        return [
            'count' => (int) $row->row_count,
            'amount' => (int) $row->amount,
            'amount_paid' => (int) $row->amount_paid,
            'amount_due' => (int) $row->amount_due,
        ];
    }

    /**
     * Expenses that count: every one whose journal doesn't stand reversed (one
     * with no journal counts). The SQL twin of `! isReversed()`, and the one
     * rule every figure built from expense records goes through: the list's
     * totals and status filters, Linda's expense answer and the branch closure
     * checklist.
     */
    public function scopeCounted(Builder $query): Builder
    {
        $journal = $this->qualifyColumn('journal_id');

        return $query->where(fn (Builder $counted): Builder => $counted
            ->whereNull($journal)
            ->orWhereRaw("{$journal} not in (".self::reversedJournalsSql().')'));
    }

    /** Expenses whose journal stands reversed: the SQL twin of {@see self::isReversed()}. */
    public function scopeReversed(Builder $query): Builder
    {
        return $query->whereRaw($this->qualifyColumn('journal_id').' in ('.self::reversedJournalsSql().')');
    }

    /**
     * The ids of expense journals whose chain of reversals ends on a reversal:
     * the SQL twin of self::endsReversed(). It follows `reversed_by_journal_id`
     * from each reversed expense journal, counting the reversals, to the entry
     * that stands, and keeps the journals reversed an odd number of times.
     */
    private static function reversedJournalsSql(): string
    {
        $max = self::MAX_REVERSALS;

        return <<<SQL
            WITH RECURSIVE reversal_chain (expense_journal_id, journal_id, reversals) AS (
                SELECT expense_journal.id, expense_journal.reversed_by_journal_id, 1
                FROM accounting_journals AS expense_journal
                WHERE expense_journal.status = 'reversed'
                  AND expense_journal.reversed_by_journal_id IS NOT NULL
                  AND expense_journal.id IN (SELECT expense.journal_id FROM accounting_expenses AS expense)
                UNION ALL
                SELECT chain.expense_journal_id, reversal.reversed_by_journal_id, chain.reversals + 1
                FROM reversal_chain AS chain
                JOIN accounting_journals AS reversal ON reversal.id = chain.journal_id
                WHERE reversal.status = 'reversed'
                  AND reversal.reversed_by_journal_id IS NOT NULL
                  AND chain.reversals < {$max}
            )
            SELECT chain.expense_journal_id
            FROM reversal_chain AS chain
            JOIN accounting_journals AS standing ON standing.id = chain.journal_id
            WHERE (chain.reversals = {$max} OR NOT (standing.status = 'reversed' AND standing.reversed_by_journal_id IS NOT NULL))
              AND MOD(chain.reversals, 2) = 1
            SQL;
    }

    /**
     * Rows whose DERIVED status is `$status`.
     *
     * The SQL twin of {@see self::status()}, and it has to stay a twin: a filter
     * that disagreed with the badge would show a row under "Unpaid" whose badge
     * read "Overdue", and nobody would be able to say which was lying. A
     * reversed expense is only ever in `reversed`.
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        $today = self::today();

        $isOverdue = static fn (Builder $q): Builder => $q
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today);

        return match ($status) {
            'reversed' => $query->reversed(),
            'paid' => $query->counted()->where('settlement_status', 'paid'),
            'overdue' => $query->counted()->where('settlement_status', '!=', 'paid')->where($isOverdue),
            'partially_paid' => $query->counted()->where('settlement_status', 'partially_paid')->whereNot($isOverdue),
            'unpaid' => $query->counted()->where('settlement_status', 'unpaid')->whereNot($isOverdue),
            // An unknown status matches nothing rather than everything. The form
            // request already rejects it; this is what stops a future caller
            // that skips validation from getting the whole table back under a
            // label that says otherwise.
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Rows whose payee or reference contains `$term`, with `%`, `_` and `\`
     * matched literally (LikePattern). A blank term matches every row.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = LikePattern::contains($term);

        return $query->where(fn (Builder $q): Builder => $q
            ->where('payee', 'like', $like)
            ->orWhere('reference', 'like', $like));
    }

    /**
     * Every `{expense}` route parameter resolves only to a row in a branch the
     * signed-in user may see (BranchAccess); a row with no branch only for
     * `branches:view_all`. Another branch's row answers 404 before any
     * validation runs. Without a signed-in user the binding is unscoped.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return StaffRouteBinding::scope(
            parent::resolveRouteBindingQuery($query, $value, $field),
            fn ($query, User $user) => app(BranchAccess::class)->constrain($query, $user, $this->qualifyColumn('branch_id')),
        );
    }
}
