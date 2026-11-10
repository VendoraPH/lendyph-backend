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
 * withdrawn document keeps them. Audited.
 */
class CdaDocument extends Model
{
    /** @use HasFactory<CdaDocumentFactory> */
    use Auditable, HasFactory;

    public const STATUSES = ['active', 'withdrawn'];

    /** The subjects a document can be about, stored in `subject_type`. */
    public const SUBJECT_TYPES = ['training', 'program', 'case', 'submission'];

    protected $fillable = [
        'period_id', 'category', 'report_id', 'subject_type', 'subject_id', 'title', 'description',
        'status', 'withdraw_reason', 'expires_on', 'created_by',
    ];

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
