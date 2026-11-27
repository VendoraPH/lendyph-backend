<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a CDA report's review chain (gap round B6), copied from the
 * `cda` chain in Settings → Approval Workflow when the report is submitted
 * for review. Each submit is a new round. A step is waiting, pending (the
 * one to act on), approved, returned (where the report went back to draft)
 * or cancelled (closed before anyone acted). `closed_at` marks a round that
 * ended without finishing, or a finished one that a return or reopen closed.
 *
 * Not audited on its own: every step action writes the report's
 * `cda_workflow` audit row.
 */
class CdaReportApprovalStep extends Model
{
    public const STATUS_WAITING = 'waiting';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'report_id', 'round', 'step_order', 'name', 'role', 'status_label', 'status', 'acted_by', 'acted_at', 'remarks', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'report_id' => 'integer',
            'round' => 'integer',
            'step_order' => 'integer',
            'acted_by' => 'integer',
            'acted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CdaReport::class, 'report_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }
}
