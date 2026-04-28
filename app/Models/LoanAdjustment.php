<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanAdjustment extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'adjustment_number',
        'loan_id',
        'adjustment_type',
        'description',
        'old_values',
        'new_values',
        'status',
        'remarks',
        'adjusted_by',
        'approved_by',
        'approved_at',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'approved_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LoanAdjustment $adj) {
            $lastCode = static::query()->orderByDesc('id')->value('adjustment_number');
            $nextNum = $lastCode ? (int) substr($lastCode, 4) + 1 : 1;
            $adj->adjustment_number = 'ADJ-'.str_pad($nextNum, 6, '0', STR_PAD_LEFT);
        });
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function adjustedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
