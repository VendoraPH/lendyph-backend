<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one payment paid on one period, written by
 * RepaymentService::processRepayment() and read back by a void.
 */
class RepaymentAllocation extends Model
{
    protected $fillable = [
        'repayment_id',
        'amortization_schedule_id',
        'period_number',
        'penalty',
        'interest',
        'principal',
    ];

    protected function casts(): array
    {
        return [
            'penalty' => 'decimal:2',
            'interest' => 'decimal:2',
            'principal' => 'decimal:2',
        ];
    }

    public function repayment(): BelongsTo
    {
        return $this->belongsTo(Repayment::class);
    }

    public function amortizationSchedule(): BelongsTo
    {
        return $this->belongsTo(AmortizationSchedule::class);
    }
}
