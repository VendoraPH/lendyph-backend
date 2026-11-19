<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One run of `portfolio:snapshot` (Phase 6 EB-B): the day it recorded, whether
 * it replayed a past day, and how it ended.
 *
 * - `succeeded`: the organisation row and its lines were written and add up.
 * - `mismatch`: the lines didn't add up to the organisation row, so only the
 *   row was kept; `checks` holds each figure that differed.
 * - `failed`: the lines couldn't be worked out, so only the organisation row
 *   was kept (`line_count` null), or a write failed and the whole day rolled
 *   back; `message` says why.
 */
class PortfolioSnapshotRun extends Model
{
    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_MISMATCH = 'mismatch';

    public const STATUS_FAILED = 'failed';

    public $timestamps = false;

    protected $fillable = ['snapshot_date', 'is_replay', 'status', 'line_count', 'checks', 'message', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'is_replay' => 'boolean',
            'line_count' => 'integer',
            'checks' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
