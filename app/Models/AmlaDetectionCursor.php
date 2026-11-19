<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;

/**
 * The AMLA detector's place in the events: the last event id it screened.
 * Kept forever; it only moves forward, and the UPDATE requires the value this
 * copy read, so two runs can't both move it.
 *
 * @property int $id
 * @property string $name
 * @property int $last_event_id
 */
class AmlaDetectionCursor extends Model
{
    use AmlaKeptForever;

    protected $fillable = ['name', 'last_event_id'];

    protected function casts(): array
    {
        return ['last_event_id' => 'integer'];
    }

    protected function amlaAllowsUpdate(): bool
    {
        return $this->last_event_id > (int) $this->getOriginal('last_event_id')
            && array_diff(array_keys($this->getDirty()), ['last_event_id', 'updated_at']) === [];
    }

    protected function amlaUpdateConditions(): array
    {
        return ['last_event_id' => (int) $this->getOriginal('last_event_id')];
    }
}
