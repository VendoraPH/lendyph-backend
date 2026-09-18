<?php

namespace App\Models;

use App\Traits\Auditable;
use Carbon\CarbonImmutable;
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
     * The four statuses `ExpenseStatus` in src/types/accounting.ts carries.
     *
     * `overdue` is in this list and NOT in the column's enum on purpose: it is
     * derived on read by {@see self::status()}. See SETTLEMENT_STATUSES for the
     * three values that are actually stored.
     */
    public const STATUSES = ['unpaid', 'partially_paid', 'paid', 'overdue'];

    /** The stored settlement state. `overdue` is never one of these. */
    public const SETTLEMENT_STATUSES = ['unpaid', 'partially_paid', 'paid'];

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
     * {@see self::scopeWithStatus()} is the SQL twin of this ladder, and
     * AccountingExpenseStatusTest asserts the two agree row for row.
     */
    public function status(): string
    {
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
     * Rows whose DERIVED status is `$status`.
     *
     * The SQL twin of {@see self::status()}, and it has to stay a twin: a filter
     * that disagreed with the badge would show a row under "Unpaid" whose badge
     * read "Overdue", and nobody would be able to say which was lying.
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        $today = self::today();

        $isOverdue = static fn (Builder $q): Builder => $q
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today);

        return match ($status) {
            'paid' => $query->where('settlement_status', 'paid'),
            'overdue' => $query->where('settlement_status', '!=', 'paid')->where($isOverdue),
            'partially_paid' => $query->where('settlement_status', 'partially_paid')->whereNot($isOverdue),
            'unpaid' => $query->where('settlement_status', 'unpaid')->whereNot($isOverdue),
            // An unknown status matches nothing rather than everything. The form
            // request already rejects it; this is what stops a future caller
            // that skips validation from getting the whole table back under a
            // label that says otherwise.
            default => $query->whereRaw('1 = 0'),
        };
    }
}
