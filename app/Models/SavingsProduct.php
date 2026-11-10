<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\SavingsProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A savings product (design §1): a passbook savings product (`regular`) or a
 * time deposit product (`time_deposit`, with the terms it offers and the
 * regular product whose rate pays an early termination). Rates live in
 * SavingsProductRate. Created inactive; turned on only when the books are
 * ready (SavingsBooks::assertCanActivate()).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property string $interest_crediting
 * @property string $minimum_opening_balance
 * @property string $maintaining_balance
 * @property string $minimum_adb_for_interest
 * @property list<int>|null $term_options_days
 * @property int|null $pretermination_product_id
 * @property bool $is_active
 */
class SavingsProduct extends Model
{
    /** @use HasFactory<SavingsProductFactory> */
    use Auditable, HasFactory;

    public const KIND_REGULAR = 'regular';

    public const KIND_TIME_DEPOSIT = 'time_deposit';

    public const KINDS = [self::KIND_REGULAR, self::KIND_TIME_DEPOSIT];

    public const CREDITING = ['monthly', 'quarterly', 'semi_annual', 'annual'];

    protected $fillable = [
        'code',
        'name',
        'kind',
        'interest_crediting',
        'minimum_opening_balance',
        'maintaining_balance',
        'minimum_adb_for_interest',
        'term_options_days',
        'pretermination_product_id',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'minimum_opening_balance' => 'decimal:2',
            'maintaining_balance' => 'decimal:2',
            'minimum_adb_for_interest' => 'decimal:2',
            'term_options_days' => 'array',
            'pretermination_product_id' => 'integer',
            'is_active' => 'boolean',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function isTimeDeposit(): bool
    {
        return $this->kind === self::KIND_TIME_DEPOSIT;
    }

    public function rates(): HasMany
    {
        return $this->hasMany(SavingsProductRate::class, 'product_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(SavingsAccount::class, 'product_id');
    }

    public function timeDeposits(): HasMany
    {
        return $this->hasMany(TimeDeposit::class, 'product_id');
    }

    public function interestRuns(): HasMany
    {
        return $this->hasMany(SavingsInterestRun::class, 'product_id');
    }

    /** Time deposit products whose early terminations this product's rate pays. */
    public function preterminationFor(): HasMany
    {
        return $this->hasMany(self::class, 'pretermination_product_id');
    }

    public function preterminationProduct(): BelongsTo
    {
        return $this->belongsTo(self::class, 'pretermination_product_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
