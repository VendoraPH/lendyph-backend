<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
 *
 * The ID document is optional: `id_type` and `id_number` are both set or both
 * null. A walk-in saved without one is matched against the others by name and
 * mobile number instead (see findPossibleDuplicate()).
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

    /**
     * Mobile numbers compared by their last 10 digits: `0917 111 2222`,
     * `+63 917-111-2222` and `639171112222` are one number. SQL twin of
     * comparableMobile(); MySQL 8 only (REGEXP_REPLACE).
     */
    private const COMPARABLE_MOBILE_COLUMN = "RIGHT(REGEXP_REPLACE(mobile_number, '[^0-9]', ''), 10)";

    /** Case-insensitive, with runs of whitespace collapsed and the ends trimmed. */
    public static function comparableName(string $fullName): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $fullName)));
    }

    /** Digits only, last 10 kept, so a leading `63` and a leading `0` compare the same. */
    public static function comparableMobile(string $mobileNumber): string
    {
        return substr((string) preg_replace('/\D/', '', $mobileNumber), -10);
    }

    /**
     * The first active walk-in (soft-deleted ones excluded) with the same name
     * and mobile number, other than $ignoreId. The mobile number narrows the
     * rows in SQL; the name is compared here so both sides use one normaliser.
     */
    public static function findPossibleDuplicate(string $fullName, string $mobileNumber, ?int $ignoreId = null): ?self
    {
        $mobile = self::comparableMobile($mobileNumber);
        $name = self::comparableName($fullName);

        if ($mobile === '' || $name === '') {
            return null;
        }

        return self::query()
            ->whereRaw(self::COMPARABLE_MOBILE_COLUMN.' = ?', [$mobile])
            ->when($ignoreId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->orderBy('id')
            ->get()
            ->first(fn (self $candidate) => self::comparableName($candidate->full_name) === $name);
    }

    public function transactions(): HasMany
    {
        // Explicit key: Laravel would infer `g_cash_non_member_id` from the class name.
        return $this->hasMany(GCashTransaction::class, 'gcash_non_member_id');
    }
}
