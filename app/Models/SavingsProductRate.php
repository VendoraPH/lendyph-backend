<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\SavingsAppendOnly;
use Database\Factories\SavingsProductRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row of a product's append-only rate history (design §1). The rate in
 * force on a date is the latest `effective_from` on or before it, ties to
 * the highest id (RateBook). `term_days` is null on a regular product and one
 * of the offered terms on a time deposit product. Never updated or deleted.
 *
 * Tiers (gap round S2): the rows sharing one `effective_from` are that date's
 * ladder. `min_balance` is the lowest closing balance that earns the row's
 * rate; null is the base tier. A same-day correction is the highest id per
 * (`effective_from`, `min_balance`). Time deposit rates never carry one.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $term_days
 * @property string $annual_rate
 * @property string|null $min_balance
 * @property Carbon $effective_from
 */
class SavingsProductRate extends Model
{
    /** @use HasFactory<SavingsProductRateFactory> */
    use Auditable, HasFactory, SavingsAppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = [
        'product_id',
        'term_days',
        'annual_rate',
        'min_balance',
        'effective_from',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'term_days' => 'integer',
            'annual_rate' => 'decimal:4',
            'min_balance' => 'decimal:2',
            'effective_from' => 'date',
            'created_by' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SavingsProduct::class, 'product_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
