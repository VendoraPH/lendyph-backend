<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A loan product a penalty configuration covers. A product sits in at most
 * one active configuration (PenaltyConfigService). No timestamps: the
 * configuration's own audit rows name its products.
 */
class PenaltyConfigProduct extends Pivot
{
    protected $table = 'penalty_config_products';

    public $timestamps = false;
}
