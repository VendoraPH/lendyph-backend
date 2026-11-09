<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one Credit Scoring settings row: the scorecard revision in use, the
 * debt service ratio levels (manageable, caution), the share of income the
 * suggested maximum payment may take, policy limits and the privacy notice.
 * Seeded by the migration; audited.
 */
class CreditScoringSetting extends Model
{
    use Auditable;

    protected $fillable = [
        'current_revision_id',
        'dsr_manageable_max',
        'dsr_caution_max',
        'capacity_share',
        'max_active_loans',
        'hard_past_due_days',
        'decline_alert_points',
        'notice_version',
        'privacy_notice',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'dsr_manageable_max' => 'decimal:4',
            'dsr_caution_max' => 'decimal:4',
            'capacity_share' => 'decimal:4',
            'max_active_loans' => 'integer',
            'hard_past_due_days' => 'integer',
            'decline_alert_points' => 'integer',
        ];
    }

    /**
     * The settings row. The migration seeds it, so this never creates one.
     */
    public static function current(): self
    {
        return static::query()->orderBy('id')->firstOrFail();
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(CreditScorecardRevision::class, 'current_revision_id');
    }
}
