<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\SavingsHoldFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An amount held on an account (design §1): a legal or manual hold, or (v2)
 * one a loan places (`loan_retention`, with `source_type`/`source_id`). An
 * active hold not yet past `expires_on` reduces the available balance.
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

    public const TYPES = ['legal', 'manual', 'loan_retention'];

    public const STATUSES = ['active', 'released', 'expired'];

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
