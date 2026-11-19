<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;

/**
 * One source's line of the nightly AMLA coverage check, for one Manila day
 * (AMLA design §4.5): its monitored rows, how many have events, how many
 * failed, and how many are Unscreened (no event yet). Kept as written.
 *
 * @property int $id
 * @property string $source
 * @property int $source_rows
 * @property int $events
 * @property int $failed
 * @property int $unscreened
 * @property int $pending
 */
class AmlaCoverageCheck extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    protected $fillable = ['day', 'source', 'source_rows', 'events', 'failed', 'unscreened', 'pending'];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'source_rows' => 'integer',
            'events' => 'integer',
            'failed' => 'integer',
            'unscreened' => 'integer',
            'pending' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
