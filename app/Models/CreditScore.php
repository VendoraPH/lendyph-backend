<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One calculated credit score, and the member's score history: a row is
 * written only when the score, band, confidence or revision changes.
 *
 * Never changed once written; update() and delete() throw. `context` is
 * `nightly` for the member's own score (the nightly run and the minute job)
 * and `assessment` for a score taken for one loan application (`loan_id`).
 * Advisory only: nothing reads it to change a loan.
 */
class CreditScore extends Model
{
    public const UPDATED_AT = null;

    public const CONTEXT_NIGHTLY = 'nightly';

    public const CONTEXT_ASSESSMENT = 'assessment';

    public const STATUS_SCORED = 'scored';

    public const STATUS_INSUFFICIENT_DATA = 'insufficient_data';

    protected $fillable = [
        'borrower_id',
        'loan_id',
        'revision_id',
        'revision_version',
        'context',
        'score_type',
        'status',
        'score',
        'risk_level',
        'confidence',
        'confidence_reason',
        'limited_history',
        'category_scores',
        'capacity',
        'data_used',
        'recommendation',
        'recalculation_reason',
        'previous_score',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'previous_score' => 'integer',
            'limited_history' => 'boolean',
            'category_scores' => 'array',
            'capacity' => 'array',
            'data_used' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    protected function performUpdate($query): never
    {
        throw new LogicException("Credit score {$this->getKey()} is immutable; a recalculation writes a new row.");
    }

    protected function performDeleteOnModel(): never
    {
        throw new LogicException("Credit score {$this->getKey()} is immutable and can't be deleted.");
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CreditScorecardRevision::class, 'revision_id');
    }

    public function factors(): HasMany
    {
        return $this->hasMany(CreditScoreFactor::class)->orderBy('id');
    }
}
