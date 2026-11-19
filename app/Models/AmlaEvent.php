<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer money movement, harvested read-only from its source row
 * (AMLA design §4.2–4.4): keyed by (source, source_id, kind), with the source
 * row's own amount as Payment History, the savings lists and the GCash list
 * show it, and the branch BranchAttribution gives it. Internal movements are
 * kept and linked, but `is_internal` and never new funds.
 *
 * Kept forever. The one change it allows is the reversal mark, written once
 * when its source is voided or reversed ({@see self::markReversed()}).
 *
 * @property int $id
 * @property string $source
 * @property int $source_id
 * @property string $kind
 * @property string $direction in|out
 * @property bool $is_internal
 * @property string|null $channel
 * @property string $amount
 * @property int|null $borrower_id
 * @property int|null $gcash_non_member_id
 * @property int|null $branch_id
 * @property CarbonInterface $occurred_on
 * @property CarbonInterface|null $recorded_at
 * @property string|null $reference
 * @property string|null $channel_reference
 * @property string|null $linked_source
 * @property int|null $linked_source_id
 * @property string $harvested_by monitor|history
 * @property CarbonInterface|null $reversed_at
 * @property string|null $reversal_source
 * @property int|null $reversal_source_id
 */
class AmlaEvent extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    /** @var list<string> the only columns ever written after the insert, once */
    public const REVERSAL_COLUMNS = ['reversed_at', 'reversal_source', 'reversal_source_id'];

    /** @var array<string, string> each kind in words */
    public const KINDS = [
        'loan_payment' => 'Loan payment',
        'loan_release' => 'Loan release',
        'loan_restructure' => 'Restructure (carried principal)',
        'restructure_deductions_collected' => 'Restructure deductions collected',
        'savings_deposit' => 'Savings deposit',
        'savings_withdrawal' => 'Savings withdrawal',
        'savings_closing' => 'Savings closing payout',
        'savings_transfer_in' => 'Savings transfer in',
        'savings_transfer_out' => 'Savings transfer out',
        'savings_interest' => 'Savings interest',
        'savings_withholding_tax' => 'Savings withholding tax',
        'savings_fee' => 'Savings fee',
        'savings_to_share_capital' => 'Savings to share capital',
        'savings_to_time_deposit' => 'Savings to time deposit',
        'savings_from_time_deposit' => 'Time deposit paid into savings',
        'savings_reversal' => 'Savings reversal',
        'time_deposit_placement' => 'Time deposit placement',
        'time_deposit_payout' => 'Time deposit payout',
        'share_capital_contribution' => 'Share capital contribution',
        'share_capital_withdrawal' => 'Share capital withdrawal',
        'gcash_cash_in' => 'GCash Cash In',
        'gcash_cash_out' => 'GCash Cash Out',
    ];

    protected $fillable = [
        'source', 'source_id', 'kind', 'direction', 'is_internal', 'channel', 'amount', 'borrower_id',
        'gcash_non_member_id', 'branch_id', 'occurred_on', 'recorded_at', 'reference', 'channel_reference',
        'linked_source', 'linked_source_id', 'harvested_by', 'reversed_at', 'reversal_source', 'reversal_source_id',
    ];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'is_internal' => 'boolean',
            'amount' => 'decimal:2',
            'borrower_id' => 'integer',
            'gcash_non_member_id' => 'integer',
            'branch_id' => 'integer',
            'occurred_on' => 'date',
            'recorded_at' => 'datetime',
            'linked_source_id' => 'integer',
            'reversed_at' => 'datetime',
            'reversal_source_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** Mark the event reversed by its source's void or reversal: once, and nothing else on it. */
    public function markReversed(string $by, int $byId, CarbonInterface $at): void
    {
        $this->forceFill(['reversed_at' => $at, 'reversal_source' => $by, 'reversal_source_id' => $byId])->save();
    }

    public static function kindWords(string $kind): string
    {
        return self::KINDS[$kind] ?? ucfirst(str_replace('_', ' ', $kind));
    }

    protected function amlaAllowsUpdate(): bool
    {
        return $this->getOriginal('reversed_at') === null
            && $this->reversed_at !== null
            && array_diff(array_keys($this->getDirty()), self::REVERSAL_COLUMNS) === [];
    }

    protected function amlaUpdateConditions(): array
    {
        return ['reversed_at' => null];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
