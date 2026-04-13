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
        'description',
        'interest_rate',
        'min_interest_rate',
        'interest_method',
        'term',
        'min_term',
        'max_term',
        'frequency',
        'frequencies',
        'processing_fee',
        'min_processing_fee',
        'max_processing_fee',
        'service_fee',
        'min_service_fee',
        'max_service_fee',
        'notarial_fee',
        'custom_fees',
        'penalty_rate',
        'grace_period_days',
        'scb_required',
        'min_scb',
        'max_scb',
        'min_amount',
        'max_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'interest_rate' => 'decimal:4',
            'min_interest_rate' => 'decimal:4',
            'processing_fee' => 'decimal:4',
            'min_processing_fee' => 'decimal:4',
            'max_processing_fee' => 'decimal:4',
            'service_fee' => 'decimal:4',
            'min_service_fee' => 'decimal:4',
            'max_service_fee' => 'decimal:4',
            'notarial_fee' => 'decimal:4',
            'penalty_rate' => 'decimal:4',
            'scb_required' => 'boolean',
            'min_scb' => 'decimal:2',
            'max_scb' => 'decimal:2',
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'frequencies' => 'array',
            'custom_fees' => 'array',
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
