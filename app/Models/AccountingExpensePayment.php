<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One settlement against an accrued expense.
 *
 * Amounts are INTEGER CENTAVOS. See the migration for why each payment is its
 * own row rather than an increment on `accounting_expenses.amount_paid` — the
 * short version is that both the accrual and the settlement post with
 * `source = 'payable'`, so without a distinct postable the poster's idempotency
 * guard would hand the accrual's journal back instead of writing the payment.
 *
 * @property int $id
 * @property int $accounting_expense_id
 * @property string $date
 * @property int $amount
 * @property int $payment_account_id
 * @property int|null $journal_id
 * @property int|null $created_by
 */
class AccountingExpensePayment extends Model
{
    /** Money leaving a cash account on someone's say-so. */
    use Auditable, HasFactory;

    protected $table = 'accounting_expense_payments';

    protected $fillable = [
        'accounting_expense_id',
        'date',
        'amount',
        'payment_account_id',
        'journal_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            // A calendar date, emitted as one. See AccountingExpense::casts().
            'date' => 'date:Y-m-d',
            'amount' => 'integer',
            'accounting_expense_id' => 'integer',
            'payment_account_id' => 'integer',
            'journal_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(AccountingExpense::class, 'accounting_expense_id');
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'payment_account_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'journal_id');
    }

    /**
     * Whether the payment's journal stands reversed, by Expenses & Payables'
     * Reverse payment or by hand on the Journals screen before that was
     * refused (finish run round 5): the cash it paid out is back in the books,
     * so it no longer counts as paid. The chain rule is the expense's
     * (AccountingJournal::endsReversed()); a payment with no journal isn't
     * reversed. AccountingExpense::paidSql() is its SQL twin.
     */
    public function isReversed(): bool
    {
        return AccountingJournal::endsReversed(
            $this->journal_id === null ? null : $this->journal,
            static fn (AccountingJournal $journal): ?AccountingJournal => $journal->reversedBy,
        );
    }
}
