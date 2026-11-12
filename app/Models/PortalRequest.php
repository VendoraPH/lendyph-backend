<?php

namespace App\Models;

use Database\Factories\PortalRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A member's request from the portal (portal design §6): a profile change, a
 * savings withdrawal or a new savings account. Nothing changes until staff
 * decide it; a withdrawal request only places a hold. `branch_id` is the
 * account's branch (withdrawal) or the member's (others), set at submission,
 * and is what staff branch scope reads. `live_key` holds one open request per
 * key (`w:{account}`, `o:{member}:{product}`, `p:{member}`).
 */
class PortalRequest extends Model
{
    /** @use HasFactory<PortalRequestFactory> */
    use HasFactory;

    public const REFERENCE_PREFIX = 'REQ';

    public const TYPE_PROFILE_UPDATE = 'profile_update';

    public const TYPE_SAVINGS_WITHDRAWAL = 'savings_withdrawal';

    public const TYPE_SAVINGS_OPENING = 'savings_opening';

    public const TYPES = [self::TYPE_PROFILE_UPDATE, self::TYPE_SAVINGS_WITHDRAWAL, self::TYPE_SAVINGS_OPENING];

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_NEED_INFO = 'need_info';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW, self::STATUS_NEED_INFO, self::STATUS_APPROVED,
        self::STATUS_COMPLETED, self::STATUS_REJECTED, self::STATUS_CANCELLED, self::STATUS_EXPIRED,
    ];

    /** Statuses a request is still open in (it holds its `live_key`). */
    public const OPEN_STATUSES = [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW, self::STATUS_NEED_INFO, self::STATUS_APPROVED];

    protected $fillable = [
        'reference',
        'portal_user_id',
        'borrower_id',
        'branch_id',
        'type',
        'status',
        'details',
        'current_values',
        'amount',
        // A withdrawal request's excess-withdrawal fee, decided when it was made (gap round S1).
        'fee_amount',
        'counted_on',
        'rule_free_withdrawals',
        'rule_excess_fee',
        'savings_account_id',
        'savings_product_id',
        'savings_hold_id',
        'savings_approval_id',
        'savings_transaction_id',
        'opened_account_id',
        'decided_by',
        'decided_at',
        'expires_on',
        'live_key',
    ];

    protected function casts(): array
    {
        return [
            'portal_user_id' => 'integer',
            'borrower_id' => 'integer',
            'branch_id' => 'integer',
            'details' => 'array',
            'current_values' => 'array',
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'counted_on' => 'date',
            'rule_free_withdrawals' => 'integer',
            'rule_excess_fee' => 'decimal:2',
            'savings_account_id' => 'integer',
            'savings_product_id' => 'integer',
            'savings_hold_id' => 'integer',
            'savings_approval_id' => 'integer',
            'savings_transaction_id' => 'integer',
            'opened_account_id' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'expires_on' => 'date',
        ];
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(PortalUser::class);
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PortalRequestEvent::class, 'request_id');
    }
}
