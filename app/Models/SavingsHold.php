<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\SavingsHoldFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An amount held on an account (design §1): a legal or manual hold, one a
 * member's portal withdrawal request places (`member_request`, with
 * `source_type`/`source_id` naming the PortalRequest; portal design §6), or
 * (v2) one a loan places (`loan_retention`). An active hold not yet past
 * `expires_on` reduces the available balance.
 *
 * @property int $id
 * @property int $account_id
 * @property string $type
 * @property string $amount
 * @property string $status
 */
class SavingsHold extends Model
{
    /** @use HasFactory<SavingsHoldFactory> */
    use Auditable, HasFactory;

    public const TYPE_MEMBER_REQUEST = 'member_request';

    public const TYPES = ['legal', 'manual', 'loan_retention', self::TYPE_MEMBER_REQUEST];

    public const STATUS_ACTIVE = 'active';

    /**
     * A hold staff took off. Named rather than written as a literal so the
     * loan-status census in CollateralIntegrityGuardsTest, which scans app/
     * for literal writes of a loan's active statuses, never mistakes a
     * savings hold for a loan.
     */
    public const STATUS_RELEASED = 'released';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_RELEASED, self::STATUS_EXPIRED];

    /** Each status in words, for messages. */
    public const STATUS_WORDS = [
        self::STATUS_ACTIVE => 'active',
        self::STATUS_RELEASED => 'released',
        self::STATUS_EXPIRED => 'expired',
    ];

    protected $fillable = [
        'account_id',
        'type',
        'amount',
        'reason',
        'placed_on',
        'expires_on',
        'status',
        'released_on',
        'release_reason',
        'released_by',
        'source_type',
        'source_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'amount' => 'decimal:2',
            'placed_on' => 'date',
            'expires_on' => 'date',
            'released_on' => 'date',
            'released_by' => 'integer',
            'source_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /**
     * Holds that still hold money on a date: active, and not past
     * `expires_on` (the nightly job marks them expired afterwards).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeHolding(Builder $query, string $date): Builder
    {
        return $query->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', $date));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class, 'account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
