<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Repayment extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'receipt_number',
        'loan_id',
        'payment_date',
        'method',
        'reference_number',
        'amount_paid',
        'principal_applied',
        'interest_applied',
        'penalty_applied',
        'overdue_interest_applied',
        'current_interest_applied',
        'current_principal_applied',
        'next_interest_applied',
        'next_principal_applied',
        'overpayment',
        'balance_before',
        'balance_after',
        'payment_type',
        'status',
        'void_reason',
        'voided_by',
        'voided_at',
        'received_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount_paid' => 'decimal:2',
            'principal_applied' => 'decimal:2',
            'interest_applied' => 'decimal:2',
            'penalty_applied' => 'decimal:2',
            'overdue_interest_applied' => 'decimal:2',
            'current_interest_applied' => 'decimal:2',
            'current_principal_applied' => 'decimal:2',
            'next_interest_applied' => 'decimal:2',
            'next_principal_applied' => 'decimal:2',
            'overpayment' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Repayment $repayment) {
            $lastCode = static::query()->orderByDesc('id')->value('receipt_number');
            $nextNum = $lastCode ? (int) substr($lastCode, 4) + 1 : 1;
            $repayment->receipt_number = 'RCP-'.str_pad($nextNum, 6, '0', STR_PAD_LEFT);
        });
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
