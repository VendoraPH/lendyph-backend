<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;

/**
 * A source row the AMLA harvester couldn't turn into an event, and why, in
 * words (AMLA design §4.5). Kept as written: it reads as Unscreened until the
 * row's event exists, and the harvester tries the row again on every run.
 *
 * @property int $id
 * @property string $source
 * @property int $source_id
 * @property string $reason
 * @property string $harvested_by
 */
class AmlaEventFailure extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    protected $fillable = ['source', 'source_id', 'reason', 'harvested_by'];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
