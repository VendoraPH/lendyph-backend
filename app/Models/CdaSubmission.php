<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\CdaSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A report's filing with CDA (CDA design §1): created only by the
 * `mark_submitted` workflow action, linked to the snapshot filed. Its
 * references, status and remarks can be updated afterwards. Audited.
 */
class CdaSubmission extends Model
{
    /** @use HasFactory<CdaSubmissionFactory> */
    use Auditable, HasFactory;

    public const METHODS = ['cais', 'ecoophub', 'email', 'physical', 'other'];

    public const STATUSES = ['submitted', 'acknowledged', 'accepted', 'returned', 'requires_correction'];

    protected $fillable = [
        'report_id', 'snapshot_id', 'method', 'submitted_on', 'submitted_by_name', 'recorded_by',
        'cda_reference_no', 'acknowledgment_no', 'status', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'report_id' => 'integer',
            'snapshot_id' => 'integer',
            'submitted_on' => 'date',
            'recorded_by' => 'integer',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CdaReport::class, 'report_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(CdaReportSnapshot::class, 'snapshot_id');
    }
}
