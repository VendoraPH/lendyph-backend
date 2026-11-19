<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One transaction behind an AMLA alert. Kept forever. The one change it
 * allows is recording, once, that the rules were run again after its event
 * was voided or reversed.
 *
 * @property int $id
 * @property int $alert_id
 * @property int $event_id
 * @property CarbonInterface|null $reversal_rerun_at
 */
class AmlaAlertEvent extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    protected $fillable = ['alert_id', 'event_id', 'reversal_rerun_at'];

    protected function casts(): array
    {
        return [
            'alert_id' => 'integer',
            'event_id' => 'integer',
            'reversal_rerun_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function markRerun(CarbonInterface $at): void
    {
        $this->forceFill(['reversal_rerun_at' => $at])->save();
    }

    protected function amlaAllowsUpdate(): bool
    {
        return $this->getOriginal('reversal_rerun_at') === null
            && $this->reversal_rerun_at !== null
            && array_keys($this->getDirty()) === ['reversal_rerun_at'];
    }

    protected function amlaUpdateConditions(): array
    {
        return ['reversal_rerun_at' => null];
    }
}
