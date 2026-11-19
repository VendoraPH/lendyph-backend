<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * How far the every-minute screening has read one subject table (members,
 * walk-ins, co-makers), as the harvester's cursors do. Only its watermark
 * moves; it is never deleted.
 *
 * @property int $id
 * @property string $subject_table
 * @property Carbon $watermark
 */
class AmlaScreeningCursor extends Model
{
    use AmlaKeptForever;

    protected $fillable = ['subject_table', 'watermark'];

    protected function casts(): array
    {
        return ['watermark' => 'datetime'];
    }

    /** Only the watermark moves. */
    protected function amlaAllowsUpdate(): bool
    {
        return array_diff(array_keys($this->getDirty()), ['watermark', 'updated_at']) === [];
    }
}
