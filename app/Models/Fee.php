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
        'applicable_product_ids',
        'conditions',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'applicable_product_ids' => 'array',
            'conditions' => 'array',
        ];
    }
}
