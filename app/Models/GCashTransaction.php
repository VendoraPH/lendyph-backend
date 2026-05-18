<?php

namespace App\Models;

use Database\Factories\GCashTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GCashTransaction extends Model
{
    /** @use HasFactory<GCashTransactionFactory> */
    use HasFactory;

    protected $table = 'gcash_transactions';

    protected $fillable = [
        'reference_no',
        'transaction_date',
        'type',
        'amount',
        'charge_amount',
        'total_amount',
        'status',
        'borrower_id',
        'transactor_user_id',
        'remarks',
        'paid_at',
        'paid_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'datetime',
            'amount' => 'decimal:2',
            'charge_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function transactor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transactor_user_id');
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }
}
