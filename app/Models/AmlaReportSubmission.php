<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One manual filing step of an AMLA report (decision 16): submitted on the
 * AMLC portal, accepted, rejected or correction required, each with the
 * facility's reference, its date and uploaded evidence (private disk; name
 * encrypted). Kept as written: the submission history.
 *
 * @property int $id
 * @property int $report_id
 * @property string $status
 * @property string $reference
 * @property CarbonInterface $occurred_on
 * @property string|null $note
 * @property string $evidence_path
 * @property string $evidence_name
 * @property int $recorded_by
 */
class AmlaReportSubmission extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    protected $fillable = [
        'report_id', 'status', 'reference', 'occurred_on', 'note', 'evidence_path', 'evidence_name', 'evidence_mime',
        'evidence_size', 'evidence_sha256', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'report_id' => 'integer',
            'occurred_on' => 'date',
            'note' => 'encrypted',
            'evidence_name' => 'encrypted',
            'evidence_size' => 'integer',
            'recorded_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
