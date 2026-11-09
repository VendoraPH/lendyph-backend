<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A person's credit decision on a member, beside the system's advice: the
 * decision, reason and remarks, who made it and when, and snapshots of the
 * score and recommendation it was made against. Append-only, so the original
 * recommendation is never erased; update() and delete() throw.
 */
class CreditDecision extends Model
{
    use Auditable;

    public const UPDATED_AT = null;

    public const DECISIONS = ['approve', 'decline', 'refer', 'hold'];

    protected $fillable = [
        'borrower_id',
        'loan_id',
        'credit_score_id',
        'decision',
        'reason',
        'remarks',
        'score_snapshot',
        'recommendation_snapshot',
        'revision_version',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'score_snapshot' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    protected function performUpdate($query): never
    {
        throw new LogicException("Credit decision {$this->getKey()} is append-only; record a new decision instead.");
    }

    protected function performDeleteOnModel(): never
    {
        throw new LogicException("Credit decision {$this->getKey()} is append-only and can't be deleted.");
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function creditScore(): BelongsTo
    {
        return $this->belongsTo(CreditScore::class);
    }

    public function decidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
