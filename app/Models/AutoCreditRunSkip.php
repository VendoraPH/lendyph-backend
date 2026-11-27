<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pledge a share capital auto-credit run skipped, with the reason in
 * words (finish run round 7): its member was already credited by hand in the
 * run's month. Written once, with its run.
 *
 * @property int $run_id
 * @property int $pledge_id
 * @property int $borrower_id
 * @property string $amount
 * @property string $reason
 */
class AutoCreditRunSkip extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'run_id',
        'pledge_id',
        'borrower_id',
        'amount',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'pledge_id' => 'integer',
            'borrower_id' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutoCreditRun::class, 'run_id');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }
}
