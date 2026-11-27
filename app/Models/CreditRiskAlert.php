<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A credit risk alert: a member's score dropped by the settings' decline
 * points or more, or moved into a high risk band. Staff-facing only, shown on
 * Risk Monitoring; never sent to the member.
 */
class CreditRiskAlert extends Model
{
    protected $fillable = [
        'borrower_id',
        'credit_score_id',
        'kind',
        'previous_score',
        'current_score',
        'previous_risk_level',
        'current_risk_level',
        'severity',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'previous_score' => 'integer',
            'current_score' => 'integer',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function creditScore(): BelongsTo
    {
        return $this->belongsTo(CreditScore::class);
    }
}
