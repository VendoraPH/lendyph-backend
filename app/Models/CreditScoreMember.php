<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Each member's current credit score, one row per member, so the score lists
 * read one row each. `checked_at` is the last recalculation; `calculated_at`
 * is when the current score row was written. Kept by the Recalculator only.
 */
class CreditScoreMember extends Model
{
    protected $fillable = [
        'borrower_id',
        'branch_id',
        'credit_score_id',
        'previous_credit_score_id',
        'status',
        'score_type',
        'score',
        'risk_level',
        'confidence',
        'previous_score',
        'trend',
        'has_active_loan',
        'past_due',
        'calculated_at',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'previous_score' => 'integer',
            'has_active_loan' => 'boolean',
            'past_due' => 'boolean',
            'calculated_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creditScore(): BelongsTo
    {
        return $this->belongsTo(CreditScore::class);
    }

    public function previousCreditScore(): BelongsTo
    {
        return $this->belongsTo(CreditScore::class, 'previous_credit_score_id');
    }
}
