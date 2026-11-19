<?php

namespace App\Models;

use App\Services\BranchAccess;
use App\Services\Branches\InterbranchTransfers;
use App\Support\StaffRouteBinding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money moved from one branch to another through 1190 Interbranch Clearing
 * (Phase 6, multi-branch decision 9). Written only through
 * {@see InterbranchTransfers}, which posts each leg
 * through JournalPoster and writes the audit rows in words.
 *
 * Statuses: pending approval, then in transit (approved: the source branch
 * has posted Dr 1190 / Cr its money account), then received (the destination
 * posted Dr its money account / Cr 1190). A pending transfer can be rejected
 * by a checker or cancelled by its maker; one in transit or received can be
 * reversed, which reverses each posted leg.
 *
 * @property int $id
 * @property string|null $transfer_number
 * @property string $status
 * @property int $source_branch_id
 * @property int $destination_branch_id
 * @property int $from_account_id
 * @property int $to_account_id
 * @property int $amount centavos
 * @property string $purpose
 * @property string|null $reference
 * @property int $created_by
 * @property int|null $approved_by
 * @property int|null $dispatch_journal_id
 * @property int|null $received_by
 * @property int|null $receipt_journal_id
 * @property int|null $rejected_by
 * @property string|null $rejection_note
 * @property int|null $reversed_by
 * @property string|null $reversal_reason
 * @property int|null $dispatch_reversal_journal_id
 * @property int|null $receipt_reversal_journal_id
 */
class InterbranchTransfer extends Model
{
    public const CODE_PREFIX = 'IBT';

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REVERSED = 'reversed';

    /** Each status in words, as the screens show it. */
    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Pending approval',
        self::STATUS_IN_TRANSIT => 'In transit',
        self::STATUS_RECEIVED => 'Received',
        self::STATUS_REJECTED => 'Rejected',
        self::STATUS_CANCELLED => 'Cancelled',
        self::STATUS_REVERSED => 'Reversed',
    ];

    /** The columns that name a journal this transfer posted. */
    public const JOURNAL_COLUMNS = ['dispatch_journal_id', 'receipt_journal_id', 'dispatch_reversal_journal_id', 'receipt_reversal_journal_id'];

    protected $fillable = [
        'source_branch_id',
        'destination_branch_id',
        'from_account_id',
        'to_account_id',
        'amount',
        'purpose',
        'reference',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'source_branch_id' => 'integer',
            'destination_branch_id' => 'integer',
            'from_account_id' => 'integer',
            'to_account_id' => 'integer',
            'created_by' => 'integer',
            'approved_by' => 'integer',
            'received_by' => 'integer',
            'rejected_by' => 'integer',
            'reversed_by' => 'integer',
            'dispatch_journal_id' => 'integer',
            'receipt_journal_id' => 'integer',
            'dispatch_reversal_journal_id' => 'integer',
            'receipt_reversal_journal_id' => 'integer',
            'approved_at' => 'datetime',
            'received_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'to_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function dispatchJournal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'dispatch_journal_id');
    }

    public function receiptJournal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'receipt_journal_id');
    }

    public function dispatchReversalJournal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'dispatch_reversal_journal_id');
    }

    public function receiptReversalJournal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'receipt_reversal_journal_id');
    }

    /** Everything the resource reads, in a fixed number of queries. */
    public function scopeWithDetails(Builder $query): Builder
    {
        return $query->with([
            'sourceBranch:id,name,code',
            'destinationBranch:id,name,code',
            'fromAccount:id,code,name',
            'toAccount:id,code,name',
            'creator:id,first_name,last_name',
            'approver:id,first_name,last_name',
            'receiver:id,first_name,last_name',
            'rejecter:id,first_name,last_name',
            'reverser:id,first_name,last_name',
            'dispatchJournal:id,journal_no',
            'receiptJournal:id,journal_no',
            'dispatchReversalJournal:id,journal_no',
            'receiptReversalJournal:id,journal_no',
        ]);
    }

    /**
     * The transfers `$user` may see: those whose source or destination is one
     * of their branches (every one with `branches:view_all`).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $branchIds = app(BranchAccess::class)->for($user);

        if ($branchIds === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereIn($this->qualifyColumn('source_branch_id'), $branchIds)
            ->orWhereIn($this->qualifyColumn('destination_branch_id'), $branchIds));
    }

    /**
     * Every `{interbranchTransfer}` route parameter resolves only to a
     * transfer the signed-in user may see ({@see self::scopeVisibleTo()});
     * any other answers 404 like a missing one, before any check runs.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return StaffRouteBinding::scope(
            parent::resolveRouteBindingQuery($query, $value, $field),
            fn ($query, User $user) => $query->visibleTo($user),
        );
    }

    /** Whole days since the transfer left its source branch, in Manila; null until approved. */
    public function ageInDays(): ?int
    {
        return $this->approved_at === null
            ? null
            : (int) $this->approved_at->copy()->startOfDay()->diffInDays(now()->startOfDay());
    }
}
