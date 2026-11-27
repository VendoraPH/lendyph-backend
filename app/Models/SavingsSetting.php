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
 * alert. Audited like ReminderSetting. The notices (gap round S4): the days
 * before a time deposit matures on which it is announced (`notice_days`, 0 is
 * the day itself) and how many days before an account would turn dormant it
 * is listed as approaching and warned (`dormancy_warning_days`).
 *
 * @property string $tier1_limit
 * @property string $tier2_limit
 * @property int $tier3_checkers
 * @property int $dormancy_months
 * @property string $large_transaction_alert
 * @property list<int>|null $notice_days
 * @property int $dormancy_warning_days
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
        'notice_days' => [30, 15, 7, 3, 0],
        'dormancy_warning_days' => 60,
    ];

    protected $fillable = [
        'tier1_limit',
        'tier2_limit',
        'tier3_checkers',
        'dormancy_months',
        'large_transaction_alert',
        'notice_days',
        'dormancy_warning_days',
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
            'notice_days' => 'array',
            'dormancy_warning_days' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /** The settings row, created with the defaults if a database lost it. */
    public static function current(): self
    {
        return static::query()->orderBy('id')->first() ?? static::query()->create(self::DEFAULTS);
    }

    /**
     * The time deposit notice days, largest first, each once (the default
     * when none are stored).
     *
     * @return list<int>
     */
    public function noticeDays(): array
    {
        $days = array_map('intval', $this->notice_days ?? self::DEFAULTS['notice_days']);
        $days = array_values(array_unique($days));
        rsort($days);

        return $days;
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
