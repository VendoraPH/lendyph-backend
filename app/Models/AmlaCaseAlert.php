<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;

/** An alert escalated to an AMLA case; an alert joins one case only. Kept forever. */
class AmlaCaseAlert extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    protected $fillable = ['case_id', 'alert_id'];
}
