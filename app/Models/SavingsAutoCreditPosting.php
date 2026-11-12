<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account credited for one cycle (gap round S3), naming the instruction
 * it followed and its deposit. Unique per (account, cycle), so a re-run, or a
 * new instruction on the same slot, posts nothing.
 *
 * @property int $id
 * @property int $instruction_id
 * @property int $transaction_id
 */
class SavingsAutoCreditPosting extends Model
{
    public const UPDATED_AT = null;

    /** The unique index that credits an account once per cycle. */
    public const CYCLE_INDEX = 'savings_auto_credit_postings_cycle_unique';

    protected $fillable = [
        'instruction_id',
        'account_id',
        'run_id',
        'cycle_date',
        'transaction_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'cycle_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function instruction(): BelongsTo
    {
        return $this->belongsTo(SavingsAutoCreditInstruction::class, 'instruction_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class, 'account_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SavingsAutoCreditRun::class, 'run_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(SavingsTransaction::class, 'transaction_id');
    }
}
