<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One uploaded version of a watchlist (AMLA design §4.11): the UN Security
 * Council consolidated list (XML), AMLC designations or an internal list
 * (both the CSV template). Its entries never change. The only changes ever
 * written are its supersession by the next version of the same list, once,
 * and its rescreen state (pending until every subject has been screened
 * against it, with where a run stopped; a manual rescreen sets it pending
 * again). Never deleted.
 *
 * @property int $id
 * @property string $source un|amlc|internal
 * @property string $version_label
 * @property string $file_sha256
 * @property int $entry_count
 * @property string $status active|superseded
 * @property Carbon|null $superseded_at
 * @property string $rescreen_status pending|done
 * @property string|null $rescreen_table the subject table a stopped rescreen resumes in
 * @property int|null $rescreen_after_id the last subject id it screened there
 * @property Carbon|null $rescreened_at
 * @property int $uploaded_by
 */
class AmlaWatchlistVersion extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    /** @var array<string, array{words: string, format: string}> */
    public const SOURCES = [
        'un' => ['words' => 'UN Security Council consolidated list', 'format' => 'xml'],
        'amlc' => ['words' => 'AMLC designations', 'format' => 'csv'],
        'internal' => ['words' => 'Internal list', 'format' => 'csv'],
    ];

    public const ACTIVE = 'active';

    public const SUPERSEDED = 'superseded';

    public const RESCREEN_PENDING = 'pending';

    public const RESCREEN_DONE = 'done';

    protected $fillable = ['source', 'version_label', 'file_sha256', 'entry_count', 'status', 'superseded_at', 'rescreen_status', 'rescreen_table', 'rescreen_after_id', 'rescreened_at', 'uploaded_by'];

    protected function casts(): array
    {
        return [
            'entry_count' => 'integer',
            'rescreen_after_id' => 'integer',
            'superseded_at' => 'datetime',
            'rescreened_at' => 'datetime',
            'uploaded_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** Only its one supersession, or its rescreen state; never its list or entries. */
    protected function amlaAllowsUpdate(): bool
    {
        $dirty = array_keys($this->getDirty());

        if (array_diff($dirty, ['status', 'superseded_at']) === []) {
            return $this->getOriginal('status') === self::ACTIVE && $this->status === self::SUPERSEDED && $this->superseded_at !== null;
        }

        return array_diff($dirty, ['rescreen_status', 'rescreen_table', 'rescreen_after_id', 'rescreened_at']) === []
            && in_array($this->rescreen_status, [self::RESCREEN_PENDING, self::RESCREEN_DONE], true);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(AmlaWatchlistEntry::class, 'version_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
