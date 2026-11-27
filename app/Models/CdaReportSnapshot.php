<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\CdaReportSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A finalized report frozen (CDA design §5): the canonical JSON payload and
 * its SHA-256 checksum, by version. Append-only: the payload and checksum
 * never change and a row is never deleted; a reopen only marks it superseded
 * (`superseded_at`, `reopened_by`, `reopen_reason`).
 *
 * @property int $version
 * @property string $payload
 * @property string $checksum
 */
class CdaReportSnapshot extends Model
{
    /** @use HasFactory<CdaReportSnapshotFactory> */
    use Auditable, HasFactory;

    /** The payload is the whole report; its checksum identifies it on the audit row. */
    protected array $auditRedacted = ['payload'];

    /** What a reopen may set on a snapshot. */
    private const SUPERSEDE_FIELDS = ['superseded_at', 'reopened_by', 'reopen_reason', 'updated_at'];

    protected $fillable = [
        'report_id', 'version', 'template_id', 'payload', 'checksum', 'finalized_by', 'finalized_at',
        'superseded_at', 'reopened_by', 'reopen_reason',
    ];

    protected function casts(): array
    {
        return [
            'report_id' => 'integer',
            'version' => 'integer',
            'template_id' => 'integer',
            'finalized_by' => 'integer',
            'finalized_at' => 'datetime',
            'superseded_at' => 'datetime',
            'reopened_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CdaReportSnapshot $snapshot): void {
            if (array_diff(array_keys($snapshot->getDirty()), self::SUPERSEDE_FIELDS) !== []) {
                throw new LogicException('A CDA report snapshot is append-only: only a reopen may mark it superseded.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('A CDA report snapshot is never deleted.');
        });
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CdaReport::class, 'report_id');
    }
}
