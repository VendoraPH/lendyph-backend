<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One saved scorecard: its categories, factors, risk bands and policy rules.
 *
 * Never changed once written: a new scorecard is a new revision, and every
 * credit score names the revision that produced it, so an old score always
 * reads against the rules it was calculated with. update() and delete()
 * throw. `validated` stays false until the weights are tested against the
 * cooperative's own loan outcomes.
 *
 * A review of the weights and risk levels is recorded beside the revision, in
 * `credit_scorecard_reviews` (revision 1.0: reviewed and kept by the owner on
 * 2026-11-11). isChecked() is what the API reports as `validated`: tested or
 * reviewed. A revision saved later from Scorecard Configuration has neither.
 */
class CreditScorecardRevision extends Model
{
    use Auditable;

    protected $fillable = [
        'version',
        'engine_version',
        'categories',
        'factors',
        'risk_bands',
        'policy_rules',
        'validated',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'factors' => 'array',
            'risk_bands' => 'array',
            'policy_rules' => 'array',
            'validated' => 'boolean',
        ];
    }

    protected function performUpdate($query): never
    {
        throw new LogicException("Scorecard revision {$this->getKey()} is immutable; save a new revision instead.");
    }

    protected function performDeleteOnModel(): never
    {
        throw new LogicException("Scorecard revision {$this->getKey()} is immutable and can't be deleted.");
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function review(): HasOne
    {
        return $this->hasOne(CreditScorecardReview::class, 'revision_id');
    }

    /**
     * Whether the weights were tested (`validated`) or reviewed (a
     * CreditScorecardReview row). The API's `validated` reads this.
     */
    public function isChecked(): bool
    {
        return (bool) $this->validated || $this->review()->exists();
    }
}
