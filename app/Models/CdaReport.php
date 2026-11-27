<?php

namespace App\Models;

use Database\Factories\CdaReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One CDA report instance: a report type and part (annual, or mediation's
 * h1 and h2) in a reporting period, with the template selected when it was
 * created (CDA design §1, §5).
 *
 * Not Auditable: readiness and issues are refreshed often, and the workflow
 * writes one explicit audit row per transition (from, to, reason) instead.
 *
 * @property int $period_id
 * @property string $report_type
 * @property string $part
 * @property int|null $template_id
 * @property string $status
 * @property bool $applicable
 * @property Carbon $default_due_on
 * @property Carbon|null $due_override_on
 */
class CdaReport extends Model
{
    /** @use HasFactory<CdaReportFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_FOR_REVIEW = 'for_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_FINALIZED = 'finalized';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_FOR_CORRECTION = 'for_correction';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_FOR_REVIEW, self::STATUS_APPROVED, self::STATUS_FINALIZED,
        self::STATUS_SUBMITTED, self::STATUS_ACCEPTED, self::STATUS_FOR_CORRECTION,
    ];

    /** Finalized and later: values are frozen in the snapshot. */
    public const LOCKED_STATUSES = [self::STATUS_FINALIZED, self::STATUS_SUBMITTED, self::STATUS_ACCEPTED, self::STATUS_FOR_CORRECTION];

    public const SUB_STATUS_RETURNED = 'returned';

    protected $fillable = [
        'period_id', 'report_type', 'part', 'template_id', 'status', 'sub_status', 'applicable', 'not_applicable_reason',
        'default_due_on', 'due_override_on', 'due_override_reference', 'readiness', 'readiness_breakdown', 'issues',
        'critical_count', 'warning_count', 'stale_at', 'refreshed_at', 'validated_at',
        'submitted_for_review_by', 'submitted_for_review_at', 'approved_by', 'approved_at', 'finalized_by', 'finalized_at',
        'current_snapshot_id',
    ];

    protected function casts(): array
    {
        return [
            'period_id' => 'integer',
            'template_id' => 'integer',
            'applicable' => 'boolean',
            'default_due_on' => 'date',
            'due_override_on' => 'date',
            'readiness' => 'integer',
            'readiness_breakdown' => 'array',
            'issues' => 'array',
            'critical_count' => 'integer',
            'warning_count' => 'integer',
            'stale_at' => 'datetime',
            'refreshed_at' => 'datetime',
            'validated_at' => 'datetime',
            'submitted_for_review_by' => 'integer',
            'submitted_for_review_at' => 'datetime',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'finalized_by' => 'integer',
            'finalized_at' => 'datetime',
            'current_snapshot_id' => 'integer',
        ];
    }

    public function isLocked(): bool
    {
        return in_array($this->status, self::LOCKED_STATUSES, true);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(CdaReportingPeriod::class, 'period_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CdaReportTemplate::class, 'template_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(CdaReportValue::class, 'report_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(CdaReportSnapshot::class, 'report_id');
    }

    public function currentSnapshot(): BelongsTo
    {
        return $this->belongsTo(CdaReportSnapshot::class, 'current_snapshot_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(CdaSubmission::class, 'report_id');
    }
}
