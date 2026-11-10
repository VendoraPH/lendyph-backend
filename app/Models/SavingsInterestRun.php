<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\SavingsInterestRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One interest run: one product, one branch, one period (design §5). It is
 * the approval unit and the journal unit, like accounting_allowance_postings.
 * `live_key` ("{product}:{branch}:{period_start}") keeps one live run per
 * period; rejecting or reversing a run frees it.
 *
 * @property int $id
 * @property int $product_id
 * @property int $branch_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $status
 * @property string $total_gross
 * @property string $total_tax
 * @property string $total_net
 */
class SavingsInterestRun extends Model
{
    /** @use HasFactory<SavingsInterestRunFactory> */
    use Auditable, HasFactory;

    public const STATUSES = ['draft', 'approved', 'posted', 'rejected', 'reversed'];

    protected $fillable = [
        'product_id',
        'branch_id',
        'period_start',
        'period_end',
        'status',
        'live_key',
        'account_count',
        'total_gross',
        'total_tax',
        'total_net',
        'created_by',
        'checked_by',
        'checked_at',
        'rejection_reason',
        'posted_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
        'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'branch_id' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'account_count' => 'integer',
            'total_gross' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_net' => 'decimal:2',
            'created_by' => 'integer',
            'checked_by' => 'integer',
            'checked_at' => 'datetime',
            'posted_by' => 'integer',
            'posted_at' => 'datetime',
            'reversed_by' => 'integer',
            'reversed_at' => 'datetime',
        ];
    }

    /** The key that keeps one live run per product, branch and period. */
    public static function liveKey(int $productId, int $branchId, string $periodStart): string
    {
        return "{$productId}:{$branchId}:{$periodStart}";
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SavingsProduct::class, 'product_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SavingsInterestRunLine::class, 'run_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class, 'interest_run_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
