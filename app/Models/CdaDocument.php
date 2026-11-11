<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\CdaDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The compliance document repository (CDA design §1): one row per document,
 * by period and category, optionally about a report or a subject (a training,
 * program, case or submission). Its files are versions, never overwritten; a
 * withdrawn document keeps them. Audited; a document restricted to
 * mediation (a `mediation` category, a `case` subject, or a document of a
 * mediation report, such as its export) keeps its title, description and
 * withdrawal reason out of the audit rows.
 */
class CdaDocument extends Model
{
    /** @use HasFactory<CdaDocumentFactory> */
    use Auditable, HasFactory;

    public const STATUSES = ['active', 'withdrawn'];

    /** The category that restricts a document to `cda:view_mediation`. */
    public const MEDIATION_CATEGORY = 'mediation';

    /** The subject type that restricts a document to `cda:view_mediation`. */
    public const CASE_SUBJECT = 'case';

    /** What never reaches the audit trail for a mediation document. */
    public const MEDIATION_REDACTED = ['title', 'description', 'withdraw_reason'];

    /**
     * Set per document before each audit row is written (booted()): empty
     * for an ordinary document, MEDIATION_REDACTED for one restricted to
     * mediation, whose texts can name the parties to a dispute.
     *
     * @var list<string>
     */
    protected array $auditRedacted = [];

    /** The subjects a document can be about, stored in `subject_type`. */
    public const SUBJECT_TYPES = ['training', 'program', 'case', 'submission'];

    protected $fillable = [
        'period_id', 'category', 'report_id', 'subject_type', 'subject_id', 'title', 'description',
        'status', 'withdraw_reason', 'expires_on', 'created_by',
    ];

    protected static function booted(): void
    {
        $redact = function (CdaDocument $document): void {
            $document->auditRedacted = $document->isMediation() || $document->wasMediation() ? self::MEDIATION_REDACTED : [];
        };

        static::saving($redact);
        static::deleting($redact);
    }

    /** Restricted to mediation: a mediation category or a case subject. */
    public function isMediation(): bool
    {
        return $this->category === self::MEDIATION_CATEGORY || $this->subject_type === self::CASE_SUBJECT
            || self::isMediationReport($this->report_id);
    }

    /** Whether it was restricted to mediation before this change. */
    public function wasMediation(): bool
    {
        return $this->getOriginal('category') === self::MEDIATION_CATEGORY || $this->getOriginal('subject_type') === self::CASE_SUBJECT
            || self::isMediationReport($this->getOriginal('report_id'));
    }

    /** Whether the report is a mediation report (H1 or H2). */
    private static function isMediationReport(mixed $reportId): bool
    {
        return $reportId !== null && CdaReport::query()->whereKey((int) $reportId)->value('report_type') === self::MEDIATION_CATEGORY;
    }

    protected function casts(): array
    {
        return [
            'period_id' => 'integer',
            'report_id' => 'integer',
            'subject_id' => 'integer',
            'expires_on' => 'date',
            'created_by' => 'integer',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(CdaReportingPeriod::class, 'period_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CdaReport::class, 'report_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CdaDocumentVersion::class, 'cda_document_id');
    }
}
