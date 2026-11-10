<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\TimeDepositFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A time deposit placement (design §1, §5): principal, rates and tax
 * snapshotted at placement, the maturity figures, how it was funded and how
 * it settles. A rollover is a new placement pointing at this one
 * (`rolled_over_from_id`, unique), which posts no placement journal of its own.
 * `settled_amount` is what the settlement paid out (₱0.00 on a full rollover);
 * `settled_interest` is the gross interest and `settled_tax` the tax.
 *
 * @property int $id
 * @property string $certificate_number
 * @property int $borrower_id
 * @property int $product_id
 * @property int|null $pretermination_product_id the regular product its early termination and post-maturity interest pay at, snapshotted at placement
 * @property int|null $branch_id
 * @property string $principal
 * @property string $annual_rate
 * @property string|null $pretermination_rate
 * @property string $withholding_tax_rate
 * @property int $term_days
 * @property string $funding_method
 * @property string $status
 * @property Carbon|null $settled_on
 * @property string|null $settled_interest
 * @property string|null $settled_tax
 */
class TimeDeposit extends Model
{
    /** @use HasFactory<TimeDepositFactory> */
    use Auditable, HasFactory;

    public const STATUSES = ['active', 'matured', 'withdrawn', 'rolled_over', 'preterminated'];

    public const MATURITY_INSTRUCTIONS = ['payout', 'rollover_principal', 'rollover_all'];

    protected $fillable = [
        'certificate_number',
        'borrower_id',
        'product_id',
        'pretermination_product_id',
        'branch_id',
        'principal',
        'annual_rate',
        'pretermination_rate',
        'withholding_tax_rate',
        'term_days',
        'placement_date',
        'maturity_date',
        'gross_interest',
        'withholding_tax',
        'net_interest',
        'maturity_amount',
        'maturity_instruction',
        'funding_method',
        'funding_transaction_id',
        'payout_account_id',
        'status',
        'settled_on',
        'settled_interest',
        'settled_tax',
        'settled_amount',
        'payout_method',
        'settled_by',
        'rolled_over_from_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'borrower_id' => 'integer',
            'product_id' => 'integer',
            'pretermination_product_id' => 'integer',
            'branch_id' => 'integer',
            'principal' => 'decimal:2',
            'annual_rate' => 'decimal:4',
            'pretermination_rate' => 'decimal:4',
            'withholding_tax_rate' => 'decimal:4',
            'term_days' => 'integer',
            'placement_date' => 'date',
            'maturity_date' => 'date',
            'gross_interest' => 'decimal:2',
            'withholding_tax' => 'decimal:2',
            'net_interest' => 'decimal:2',
            'maturity_amount' => 'decimal:2',
            'funding_transaction_id' => 'integer',
            'payout_account_id' => 'integer',
            'settled_on' => 'date',
            'settled_interest' => 'decimal:2',
            'settled_tax' => 'decimal:2',
            'settled_amount' => 'decimal:2',
            'settled_by' => 'integer',
            'rolled_over_from_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SavingsProduct::class, 'product_id');
    }

    public function preterminationProduct(): BelongsTo
    {
        return $this->belongsTo(SavingsProduct::class, 'pretermination_product_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fundingTransaction(): BelongsTo
    {
        return $this->belongsTo(SavingsTransaction::class, 'funding_transaction_id');
    }

    public function payoutAccount(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class, 'payout_account_id');
    }

    public function rolledOverFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rolled_over_from_id');
    }

    public function rolledOverInto(): HasOne
    {
        return $this->hasOne(self::class, 'rolled_over_from_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class);
    }

    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
