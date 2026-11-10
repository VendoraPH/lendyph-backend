<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\SavingsAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A member's savings account (design §1). One open account per member per
 * product (`open_key` = "{borrower}:{product}" while open, null once closed),
 * in the member's branch at opening.
 *
 * `ledger_balance` is a cache of the subledger, written only by
 * SavingsLedger under the account's row lock; the transactions are the
 * record. Status changes and reactivation are audited.
 *
 * @property int $id
 * @property string $account_number
 * @property int $borrower_id
 * @property int $product_id
 * @property int $branch_id
 * @property string $status
 * @property string $ledger_balance
 * @property Carbon $opened_on
 * @property Carbon|null $last_member_activity_on
 * @property Carbon|null $dormant_since
 * @property Carbon|null $interest_credited_through
 * @property Carbon|null $closed_on
 */
class SavingsAccount extends Model
{
    /** @use HasFactory<SavingsAccountFactory> */
    use Auditable, HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DORMANT = 'dormant';

    public const STATUS_FROZEN = 'frozen';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_DORMANT, self::STATUS_FROZEN, self::STATUS_CLOSED];

    protected $fillable = [
        'account_number',
        'borrower_id',
        'product_id',
        'branch_id',
        'status',
        'ledger_balance',
        'opened_on',
        'last_member_activity_on',
        'dormant_since',
        'interest_credited_through',
        'closed_on',
        'close_reason',
        'closed_by',
        'open_key',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'borrower_id' => 'integer',
            'product_id' => 'integer',
            'branch_id' => 'integer',
            'ledger_balance' => 'decimal:2',
            'opened_on' => 'date',
            'last_member_activity_on' => 'date',
            'dormant_since' => 'date',
            'interest_credited_through' => 'date',
            'closed_on' => 'date',
            'closed_by' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /** The key that keeps one open account per member per product. */
    public static function openKey(int $borrowerId, int $productId): string
    {
        return "{$borrowerId}:{$productId}";
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SavingsProduct::class, 'product_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class, 'account_id');
    }

    public function holds(): HasMany
    {
        return $this->hasMany(SavingsHold::class, 'account_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(SavingsApproval::class, 'account_id');
    }

    public function interestRunLines(): HasMany
    {
        return $this->hasMany(SavingsInterestRunLine::class, 'account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
