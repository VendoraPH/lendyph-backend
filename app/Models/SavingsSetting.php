<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\SavingsSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The savings settings, one row (seeded by 2026_11_10_500000): the approval
 * tiers (up to `tier1_limit` posts at once, up to `tier2_limit` needs one
 * checker, above it `tier3_checkers` different checkers), the months without
 * member activity before an account turns dormant, and the large-transaction
 * alert. Audited like ReminderSetting.
 *
 * @property string $tier1_limit
 * @property string $tier2_limit
 * @property int $tier3_checkers
 * @property int $dormancy_months
 * @property string $large_transaction_alert
 */
class SavingsSetting extends Model
{
    /** @use HasFactory<SavingsSettingFactory> */
    use Auditable, HasFactory;

    public const DEFAULTS = [
        'tier1_limit' => '10000.00',
        'tier2_limit' => '50000.00',
        'tier3_checkers' => 2,
        'dormancy_months' => 24,
        'large_transaction_alert' => '500000.00',
    ];

    protected $fillable = [
        'tier1_limit',
        'tier2_limit',
        'tier3_checkers',
        'dormancy_months',
        'large_transaction_alert',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'tier1_limit' => 'decimal:2',
            'tier2_limit' => 'decimal:2',
            'tier3_checkers' => 'integer',
            'dormancy_months' => 'integer',
            'large_transaction_alert' => 'decimal:2',
            'updated_by' => 'integer',
        ];
    }

    /** The settings row, created with the defaults if a database lost it. */
    public static function current(): self
    {
        return static::query()->orderBy('id')->first() ?? static::query()->create(self::DEFAULTS);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
