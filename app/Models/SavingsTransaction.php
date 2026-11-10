<?php

namespace App\Models;

use App\Exceptions\SavingsTransactionIsAppendOnlyException;
use App\Traits\SavingsAppendOnly;
use Database\Factories\SavingsTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One posted row of the savings subledger (design §1). Append-only: the
 * model refuses every update and delete, and a mistake is corrected by a
 * `reversal` row pointing at it (unique, so a row is reversed once).
 * SavingsLedger is the only writer; it enforces the date rules and keeps
 * `balance_after` and the account's `ledger_balance` right under the lock.
 *
 * `date` is the business date; `value_date` the date the money counts for
 * interest. They are equal except on interest rows and reversals.
 *
 * Not Auditable: the row itself is the immutable record of who posted what
 * (`created_by`, null for the system), so an audit row would only repeat it.
 *
 * @property int $id
 * @property int $account_id
 * @property string $reference
 * @property string $type
 * @property Carbon $date
 * @property Carbon $value_date
 * @property string $debit
 * @property string $credit
 * @property string $balance_after
 * @property string|null $method
 * @property int|null $created_by
 */
class SavingsTransaction extends Model
{
    /** @use HasFactory<SavingsTransactionFactory> */
    use HasFactory, SavingsAppendOnly;

    public const UPDATED_AT = null;

    public const TYPES = ['deposit', 'withdrawal', 'transfer_in', 'transfer_out', 'interest', 'withholding_tax', 'closing', 'reversal'];

    /** Types that only ever add to the balance. */
    public const CREDIT_TYPES = ['deposit', 'transfer_in', 'interest'];

    /** Types that only ever take from it. */
    public const DEBIT_TYPES = ['withdrawal', 'transfer_out', 'withholding_tax', 'closing'];

    /** Types that count as the member using the account (dormancy). */
    public const MEMBER_ACTIVITY_TYPES = ['deposit', 'withdrawal', 'transfer_in', 'transfer_out'];

    /** How money moved; `savings` is a move between savings records (transfers, time deposits). */
    public const METHODS = ['cash', 'bank_transfer', 'gcash', 'maya', 'savings'];

    protected $fillable = [
        'account_id',
        'reference',
        'type',
        'date',
        'value_date',
        'debit',
        'credit',
        'balance_after',
        'method',
        'external_reference',
        'remarks',
        'counterpart_transaction_id',
        'reverses_transaction_id',
        'time_deposit_id',
        'interest_run_id',
        'approval_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'date' => 'date',
            'value_date' => 'date',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'counterpart_transaction_id' => 'integer',
            'reverses_transaction_id' => 'integer',
            'time_deposit_id' => 'integer',
            'interest_run_id' => 'integer',
            'approval_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function appendOnlyException(): string
    {
        return SavingsTransactionIsAppendOnlyException::class;
    }

    public function isCredit(): bool
    {
        return (float) $this->credit > 0;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class, 'account_id');
    }

    public function counterpart(): BelongsTo
    {
        return $this->belongsTo(self::class, 'counterpart_transaction_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_transaction_id');
    }

    public function timeDeposit(): BelongsTo
    {
        return $this->belongsTo(TimeDeposit::class);
    }

    public function interestRun(): BelongsTo
    {
        return $this->belongsTo(SavingsInterestRun::class, 'interest_run_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(SavingsApproval::class, 'approval_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
