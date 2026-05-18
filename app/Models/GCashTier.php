<?php

namespace App\Models;

use Database\Factories\GCashTierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GCashTier extends Model
{
    /** @use HasFactory<GCashTierFactory> */
    use HasFactory;

    protected $table = 'gcash_tiers';

    protected $fillable = [
        'min_amount',
        'max_amount',
        'cash_in_rate',
        'cash_out_rate',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'cash_in_rate' => 'decimal:2',
            'cash_out_rate' => 'decimal:2',
            'display_order' => 'integer',
        ];
    }
}
