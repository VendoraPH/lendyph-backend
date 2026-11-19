<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;

/**
 * The row the AMLA detector locks for each batch, so two runs never write at
 * once and reference numbers are taken one at a time. It holds no state:
 * which events are screened is AmlaEventScreening's. Kept forever.
 *
 * @property int $id
 * @property string $name
 */
class AmlaDetectionLock extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    protected $fillable = ['name'];
}
