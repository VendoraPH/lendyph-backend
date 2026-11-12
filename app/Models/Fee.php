<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Fee extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'name',
        'type',
        'value',
        'per_month',
        'min_amount',
        'max_amount',
        'formula_expression',
        'applicable_product_ids',
        'conditions',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'per_month' => 'boolean',
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'applicable_product_ids' => 'array',
            'conditions' => 'array',
        ];
    }
}
