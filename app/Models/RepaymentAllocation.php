<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one payment paid on one period, written by
 * RepaymentService::processRepayment() and read back by a void.
 *
 * On a payment recorded while the loan was defaulted, `penalty_amount_before`
 * and `penalty_months_before` keep the period's penalty as it stood before
 * the payment, so a void puts it back exactly; null on every other payment.
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
        'penalty_amount_before',
        'penalty_months_before',
    ];

    protected function casts(): array
    {
        return [
            'penalty' => 'decimal:2',
            'interest' => 'decimal:2',
            'principal' => 'decimal:2',
            'penalty_amount_before' => 'decimal:2',
            'penalty_months_before' => 'integer',
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
