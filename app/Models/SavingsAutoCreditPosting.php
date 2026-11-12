<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One instruction credited for one cycle (gap round S3), linked to its
 * deposit. Unique per (instruction, cycle), so a re-run posts nothing.
 *
 * @property int $id
 * @property int $instruction_id
 * @property int $transaction_id
 */
class SavingsAutoCreditPosting extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'instruction_id',
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

    public function run(): BelongsTo
    {
        return $this->belongsTo(SavingsAutoCreditRun::class, 'run_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(SavingsTransaction::class, 'transaction_id');
    }
}
