<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The AMLA harvester's place in one source (AMLA design §4.2): a watermark on
 * the source's own recording time, and `started_at`, the go-live from which
 * the source is monitored. Kept forever; the watermark only ever moves
 * forward, checked again inside the UPDATE so two harvests can't both move it.
 *
 * @property int $id
 * @property string $source
 * @property CarbonInterface|null $watermark
 * @property CarbonInterface|null $started_at
 */
class AmlaHarvestCursor extends Model
{
    use AmlaKeptForever;

    protected $fillable = ['source', 'watermark', 'started_at'];

    protected function casts(): array
    {
        return [
            'watermark' => 'datetime',
            'started_at' => 'datetime',
        ];
    }

    protected function amlaAllowsUpdate(): bool
    {
        $before = $this->getOriginal('watermark');

        return $before !== null
            && $this->watermark !== null
            && $this->watermark->gt($before)
            && array_diff(array_keys($this->getDirty()), ['watermark', 'updated_at']) === [];
    }

    protected function amlaUpdateConditions(): array
    {
        return ['watermark' => $this->getRawOriginal('watermark')];
    }
}
