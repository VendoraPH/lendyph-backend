<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One item in AMLA's own inbox (AMLA design §4.14): shown only in AMLA
 * screens to the compliance user it is for, never the general notification
 * bell, SMS or email. Its message names a reference and a priority or due
 * date, never a member. Kept forever; the one change is being read, once.
 *
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property string $subject_type
 * @property int $subject_id
 * @property string $message
 * @property string|null $dedupe_key
 * @property CarbonInterface|null $read_at
 */
class AmlaInboxItem extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'kind', 'subject_type', 'subject_id', 'message', 'dedupe_key', 'read_at'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'subject_id' => 'integer', 'read_at' => 'datetime', 'created_at' => 'datetime'];
    }

    protected function amlaAllowsUpdate(): bool
    {
        return $this->getOriginal('read_at') === null && $this->read_at !== null && array_keys($this->getDirty()) === ['read_at'];
    }

    protected function amlaUpdateConditions(): array
    {
        return ['read_at' => null];
    }
}
