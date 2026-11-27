<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member whose data changed since their last score, waiting for the minute
 * job (`credit-scoring:process-changes`). One row per member; a newer change
 * moves `flagged_at` forward. `attempts` counts failed scorings; a failure
 * also moves `flagged_at` to the back of the queue.
 */
class CreditScoreDirtyMember extends Model
{
    protected $fillable = [
        'borrower_id',
        'reason',
        'flagged_at',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'flagged_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }
}
