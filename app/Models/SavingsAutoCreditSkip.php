<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One instruction a savings auto-credit run skipped (gap round B7), with its
 * account, amount and the reason in words. Written once, with its run.
 *
 * @property int $id
 * @property int $run_id
 * @property int $account_id
 * @property string $reason
 */
class SavingsAutoCreditSkip extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'run_id',
        'instruction_id',
        'account_id',
        'amount',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class, 'account_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SavingsAutoCreditRun::class, 'run_id');
    }
}
