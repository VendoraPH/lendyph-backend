<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A year whose AMLA working-day calendar is confirmed, by an approved change
 * request (AMLA design §4.15). A deadline that reaches a year without one is
 * provisional. Never changed or deleted.
 *
 * @property int $id
 * @property int $year
 * @property Carbon $confirmed_at
 * @property int $request_id
 */
class AmlaCalendarYear extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    protected $fillable = ['year', 'confirmed_at', 'request_id'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'confirmed_at' => 'datetime',
            'request_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AmlaChangeRequest::class, 'request_id');
    }
}
