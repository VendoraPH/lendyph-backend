<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\CdaSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The CDA settings, one row (seeded by 2026_11_11_100100): annual reports
 * are due `annual_deadline_days` after Dec 31, mediation reports
 * `mediation_deadline_days` after each half; reminders go out
 * `reminder_days` before a deadline; `required_trainings` maps an officer
 * position to the training keys it needs; `asset_class_thresholds` holds the
 * upper total-asset bound of micro, small and medium (in pesos, two-decimal
 * strings). Audited.
 *
 * @property int $annual_deadline_days
 * @property int $mediation_deadline_days
 * @property list<int> $reminder_days
 * @property array<string, list<string>> $required_trainings
 * @property array<string, string> $asset_class_thresholds
 */
class CdaSetting extends Model
{
    /** @use HasFactory<CdaSettingFactory> */
    use Auditable, HasFactory;

    public const DEFAULTS = [
        'annual_deadline_days' => 120,
        'mediation_deadline_days' => 30,
        'reminder_days' => [30, 15, 7, 3],
        'required_trainings' => [],
        'asset_class_thresholds' => ['micro' => '3000000.00', 'small' => '15000000.00', 'medium' => '100000000.00'],
    ];

    protected $fillable = [
        'annual_deadline_days',
        'mediation_deadline_days',
        'reminder_days',
        'required_trainings',
        'asset_class_thresholds',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'annual_deadline_days' => 'integer',
            'mediation_deadline_days' => 'integer',
            'reminder_days' => 'array',
            'required_trainings' => 'array',
            'asset_class_thresholds' => 'array',
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
