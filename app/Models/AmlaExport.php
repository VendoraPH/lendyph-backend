<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One AMLA report export the server made (AMLA-B7): the report, format and
 * filters, the file on the private disk, and the user it was made for. It is
 * downloaded only through a signed link re-checked against that user (same
 * user, credentials still current, still holding the report's keys). Kept
 * forever. The one change it allows is `file_deleted_at`, set once when the
 * sweep removes its file (ReportExports::pruneExpired()).
 *
 * @property int $id
 * @property string $report_key
 * @property string $format pdf|xlsx|csv
 * @property array<string, mixed> $filters
 * @property string $title
 * @property bool $is_draft
 * @property string $file_path
 * @property int $file_size
 * @property string $sha256
 * @property int $row_count
 * @property int $user_id
 * @property Carbon|null $file_deleted_at
 */
class AmlaExport extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    public const FORMATS = ['pdf' => 'application/pdf', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv; charset=UTF-8'];

    protected $fillable = ['report_key', 'format', 'filters', 'title', 'is_draft', 'file_path', 'file_size', 'sha256', 'row_count', 'user_id', 'file_deleted_at'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_draft' => 'boolean',
            'file_size' => 'integer',
            'row_count' => 'integer',
            'user_id' => 'integer',
            'created_at' => 'datetime',
            'file_deleted_at' => 'datetime',
        ];
    }

    /** Only `file_deleted_at`, once, from empty. */
    protected function amlaAllowsUpdate(): bool
    {
        return array_keys($this->getDirty()) === ['file_deleted_at']
            && $this->getOriginal('file_deleted_at') === null
            && $this->file_deleted_at !== null;
    }

    /** @return array<string, mixed> */
    protected function amlaUpdateConditions(): array
    {
        return ['file_deleted_at' => null];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
