<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanProduct extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'name',
        'interest_rate',
        'interest_method',
        'term',
        'frequency',
        'processing_fee',
        'service_fee',
        'penalty_rate',
        'grace_period_days',
        'min_amount',
        'max_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'interest_rate' => 'decimal:4',
            'processing_fee' => 'decimal:4',
            'service_fee' => 'decimal:4',
            'penalty_rate' => 'decimal:4',
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
        ];
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
