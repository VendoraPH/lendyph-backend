<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A day that isn't an AMLA working day (AMLA design §4.15): a holiday, an AMLC
 * non-reporting day or a facility suspension, with its source, added by an
 * approved change request. Removed only by another approved request, which
 * stamps `removed_at` once; the row itself stays forever, so a past deadline
 * can always be explained.
 *
 * @property int $id
 * @property Carbon $date
 * @property string $kind
 * @property string $name
 * @property string $source
 * @property int|null $active_marker 1 while in force, NULL once removed
 * @property int $request_id
 * @property int|null $removal_request_id
 * @property Carbon|null $removed_at
 */
class AmlaCalendarEntry extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    /** @var array<string, string> each kind in words */
    public const KINDS = [
        'holiday' => 'Holiday',
        'amlc_non_reporting' => 'AMLC non-reporting day',
        'facility_suspension' => 'Facility suspension',
    ];

    private const REMOVAL_COLUMNS = ['active_marker', 'removal_request_id', 'removed_at'];

    protected $fillable = ['date', 'kind', 'name', 'source', 'active_marker', 'request_id', 'removal_request_id', 'removed_at'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'active_marker' => 'integer',
            'request_id' => 'integer',
            'removal_request_id' => 'integer',
            'removed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    /** Only its one removal, from in force to removed. */
    protected function amlaAllowsUpdate(): bool
    {
        return $this->getOriginal('removed_at') === null
            && $this->removed_at !== null
            && $this->active_marker === null
            && $this->removal_request_id !== null
            && array_diff(array_keys($this->getDirty()), self::REMOVAL_COLUMNS) === [];
    }

    /** @return array<string, mixed> */
    protected function amlaUpdateConditions(): array
    {
        return ['removed_at' => null];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AmlaChangeRequest::class, 'request_id');
    }
}
