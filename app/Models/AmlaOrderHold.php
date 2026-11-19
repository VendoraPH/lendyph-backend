<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A savings hold an AMLA order placed (decision 5). The link lives here, on
 * the AMLA side, so the hold itself reads only as a legal hold and the main
 * audit trail never names AMLA. While this row exists the hold never expires
 * (`savings:expire` skips it) and the savings hold release refuses it; only
 * the order's release lifts it. Kept as written.
 *
 * @property int $id
 * @property int $order_id
 * @property int $savings_hold_id
 * @property int $account_id
 * @property string $amount
 */
class AmlaOrderHold extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'savings_hold_id', 'account_id', 'amount'];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'savings_hold_id' => 'integer',
            'account_id' => 'integer',
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** Whether a savings hold was placed by an AMLA order. */
    public static function isOrderHold(int $savingsHoldId): bool
    {
        return static::query()->where('savings_hold_id', $savingsHoldId)->exists();
    }

    public function hold(): BelongsTo
    {
        return $this->belongsTo(SavingsHold::class, 'savings_hold_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class, 'account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(AmlaOrder::class, 'order_id');
    }
}
