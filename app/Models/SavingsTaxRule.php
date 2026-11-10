<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\SavingsAppendOnly;
use Database\Factories\SavingsTaxRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The withholding tax on deposit interest, by holder type, append-only
 * (design §1). Seeded at 0% for members (decided 2026-11-10, for the
 * accountant to confirm) and 20% for walk-ins (`non_member`, who can't open
 * accounts in v1). TaxBook reads the rule in force on a date.
 *
 * @property int $id
 * @property string $holder_type
 * @property string $rate
 * @property Carbon $effective_from
 */
class SavingsTaxRule extends Model
{
    /** @use HasFactory<SavingsTaxRuleFactory> */
    use Auditable, HasFactory, SavingsAppendOnly;

    public const UPDATED_AT = null;

    public const HOLDER_MEMBER = 'member';

    public const HOLDER_NON_MEMBER = 'non_member';

    public const HOLDER_TYPES = [self::HOLDER_MEMBER, self::HOLDER_NON_MEMBER];

    protected $fillable = [
        'holder_type',
        'rate',
        'effective_from',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'effective_from' => 'date',
            'created_by' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
