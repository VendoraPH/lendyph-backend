<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A source row the AMLA harvester couldn't turn into an event, and why, in
 * words (AMLA design §4.5). It reads as Unscreened until the row's event
 * exists. The harvester tries it again, least recently tried first, under its
 * own `harvested_by` (a history failure stays history).
 *
 * Kept forever. Two changes are allowed while it is unresolved: a later
 * `last_attempted_at`, and `resolved_at` once its event exists. Both are
 * checked again inside the UPDATE.
 *
 * @property int $id
 * @property string $source
 * @property int $source_id
 * @property string $reason
 * @property string $harvested_by
 * @property CarbonInterface|null $last_attempted_at
 * @property CarbonInterface|null $resolved_at
 */
class AmlaEventFailure extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    protected $fillable = ['source', 'source_id', 'reason', 'harvested_by', 'last_attempted_at', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'last_attempted_at' => 'datetime',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** Record another try at `$at`, and resolve it when the row's event now exists. */
    public function tried(CarbonInterface $at, bool $resolved): void
    {
        if ($this->resolved_at !== null || ($this->last_attempted_at !== null && $at->lte($this->last_attempted_at) && ! $resolved)) {
            return;
        }

        if ($this->last_attempted_at === null || $at->gt($this->last_attempted_at)) {
            $this->last_attempted_at = $at;
        }

        if ($resolved) {
            $this->resolved_at = $at;
        }

        $this->save();
    }

    protected function amlaAllowsUpdate(): bool
    {
        $before = $this->getOriginal('last_attempted_at');

        return $this->getOriginal('resolved_at') === null
            && array_diff(array_keys($this->getDirty()), ['last_attempted_at', 'resolved_at']) === []
            && ($this->last_attempted_at !== null && ($before === null || $this->last_attempted_at->gte($before)));
    }

    protected function amlaUpdateConditions(): array
    {
        return ['resolved_at' => null];
    }
}
