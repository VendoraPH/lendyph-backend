<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One saved scorecard: its categories, factors, risk bands and policy rules.
 *
 * Never changed once written: a new scorecard is a new revision, and every
 * credit score names the revision that produced it, so an old score always
 * reads against the rules it was calculated with. update() and delete()
 * throw. `validated` stays false until the weights are tested against the
 * cooperative's own loan outcomes.
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
}
