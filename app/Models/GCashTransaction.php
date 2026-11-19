<?php

namespace App\Models;

use App\Services\BranchAccess;
use App\Services\BranchAttribution;
use App\Support\StaffRouteBinding;
use Database\Factories\GCashTransactionFactory;
use Illuminate\Database\Eloquent\Builder;
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
        // The branch whose books the row posts in: where a walk-in transaction
        // was done (null for head office), or, for a member's, the member's
        // branch, stamped on creation (booted()).
        'branch_id',
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
            'branch_id' => 'integer',
        ];
    }

    /**
     * A member's transaction keeps the member's branch at the time it is
     * written (Phase 6 decision 16), so it stays in that branch's books and
     * lists if the member later moves. A walk-in's is left as recorded.
     */
    protected static function booted(): void
    {
        static::creating(function (GCashTransaction $tx): void {
            if ($tx->borrower_id !== null && $tx->branch_id === null) {
                $tx->branch_id = BranchAttribution::gcashPostingBranch($tx);
            }
        });
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

    /** The branch whose books the row posts in (null for a walk-in done at head office). */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function transactor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transactor_user_id');
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    /**
     * The rows $user may see (Phase 6 decisions 1 and 16, D6): each row
     * follows the branch whose books it posted in (BranchAttribution): a
     * member's the branch stamped when it was written (a row from before
     * that, the member's branch), a walk-in's the branch it was recorded in.
     * A walk-in recorded with no branch (head office) is visible only to
     * `branches:view_all`, like every other row with no branch. A local scope
     * only: the books, the ledger-gaps report and commands stay unscoped.
     *
     * @param  Builder<GCashTransaction>  $query
     * @return Builder<GCashTransaction>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $branchIds = app(BranchAccess::class)->for($user);

        if ($branchIds === null) {
            return $query;
        }

        return BranchAttribution::gcashInBranches($query, $branchIds);
    }

    /**
     * Every `{transaction}` GCash route parameter resolves only to a row the
     * signed-in user may see, so another branch's row answers the same 404 as
     * a missing one (StaffRouteBinding).
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return StaffRouteBinding::scope(
            parent::resolveRouteBindingQuery($query, $value, $field),
            fn ($query, User $user) => $query->visibleTo($user),
        );
    }
}
