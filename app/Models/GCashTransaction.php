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
        'gcash_non_member_id',
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

    /**
     * The walk-in this transaction served, when it was not a member.
     *
     * Exactly one of `borrower` / `nonMember` is set on every row — enforced by
     * StoreGCashTransactionRequest and a CHECK constraint on the table.
     *
     * `withTrashed`, so a transaction still names its party after that walk-in
     * has been removed from the list.
     */
    public function nonMember(): BelongsTo
    {
        return $this->belongsTo(GCashNonMember::class, 'gcash_non_member_id')->withTrashed();
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
