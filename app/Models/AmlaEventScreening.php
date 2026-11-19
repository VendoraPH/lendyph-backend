<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;

/**
 * That the AMLA detector screened one event (AMLA design §4.5, §4.7): written
 * once, in the same transaction as the event's CTR candidate and alerts, with
 * the rule and settings versions it was judged by. An event with no row is
 * unscreened, whatever its id, and coverage counts it as Unscreened. Kept
 * forever and never changed.
 *
 * @property int $id
 * @property int $event_id
 * @property int $rule_version
 * @property int $settings_version
 */
class AmlaEventScreening extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    protected $fillable = ['event_id', 'rule_version', 'settings_version'];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'rule_version' => 'integer',
            'settings_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
