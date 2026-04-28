<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CollateralType extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'name',
        'detail_field_label',
        'amount_field_label',
        'source',
        'display_order',
        'is_visible',
        'is_seed',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'is_visible' => 'boolean',
            'is_seed' => 'boolean',
        ];
    }

    public function collaterals(): HasMany
    {
        return $this->hasMany(Collateral::class);
    }
}
