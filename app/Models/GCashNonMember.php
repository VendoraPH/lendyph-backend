<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A walk-in the GCash counter serves who is not a member of the cooperative.
 *
 * Deliberately NOT a Borrower. A borrower row carries membership, KYC state and
 * a share-capital pledge, and every list that counts members reads from it — a
 * walk-in buying load once would otherwise show up in the membership figures.
 * See Borrower's own note on the member / non-member split.
 *
 * Soft-deleted, because the remove dialog promises "Transactions already
 * recorded for them are kept": a walk-in has to be able to leave the list
 * without taking the counterparty of a recorded cash movement with them.
 */
class GCashNonMember extends Model
{
    use HasFactory, SoftDeletes;

    /** Laravel would infer `g_cash_non_members`; matches GCashTransaction/GCashTier. */
    protected $table = 'gcash_non_members';

    protected $fillable = [
        'full_name',
        'mobile_number',
        'id_type',
        'id_number',
        'remarks',
    ];

    public function transactions(): HasMany
    {
        // Explicit key: Laravel would infer `g_cash_non_member_id` from the class name.
        return $this->hasMany(GCashTransaction::class, 'gcash_non_member_id');
    }
}
