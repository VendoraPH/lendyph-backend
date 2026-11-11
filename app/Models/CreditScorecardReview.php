<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A recorded review of a scorecard revision's weights and risk levels: who
 * reviewed it and on what date.
 *
 * Revisions are immutable, so a review is its own row rather than a change to
 * the revision. One per revision (unique). Never changed or deleted once
 * written: update() and delete() throw. The 2026_11_11_620200 migration and
 * `credit-scoring:mark-scorecard-reviewed` write them through the query
 * builder, with a system audit row on the revision; the time of writing is
 * that row's. CreditScorecardRevision::isChecked() reads them.
 */
class CreditScorecardReview extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'revision_id',
        'reviewed_on',
        'reviewed_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'revision_id' => 'integer',
            'reviewed_on' => 'date',
        ];
    }

    protected function performUpdate($query): never
    {
        throw new LogicException("Scorecard review {$this->getKey()} is immutable.");
    }

    protected function performDeleteOnModel(): never
    {
        throw new LogicException("Scorecard review {$this->getKey()} is immutable and can't be deleted.");
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CreditScorecardRevision::class, 'revision_id');
    }
}
