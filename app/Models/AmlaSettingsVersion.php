<?php

namespace App\Models;

use App\Services\Accounting\Money;
use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One version of the AMLA settings (AMLA design §4.6, §4.10, §4.15). Never
 * changed: an approved change request adds the next version, in force from
 * `effective_at`. Version 1 holds the documented starting points, every one
 * "to verify" (`to_verify` lists the keys still unconfirmed; `sources` names
 * what confirmed the others). Keys and meanings: AmlaSettings::DEFINITIONS.
 *
 * @property int $id
 * @property int $version
 * @property array<string, mixed> $values
 * @property list<string> $to_verify
 * @property array<string, string> $sources
 * @property Carbon $effective_at
 * @property int|null $request_id
 * @property int|null $created_by
 * @property int|null $approved_by
 */
class AmlaSettingsVersion extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    protected $fillable = ['version', 'values', 'to_verify', 'sources', 'effective_at', 'request_id', 'created_by', 'approved_by'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'values' => 'array',
            'to_verify' => 'array',
            'sources' => 'array',
            'effective_at' => 'datetime',
            'request_id' => 'integer',
            'created_by' => 'integer',
            'approved_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** The CTR threshold in centavos. */
    public function ctrThresholdCentavos(): int
    {
        return (int) Money::toCentavos((string) $this->values['ctr_threshold']);
    }

    /**
     * Whether one transaction is a CTR candidate under this version: strictly
     * above the threshold (exactly ₱500,000.00 is not), in a channel marked
     * cash-equivalent. One transaction only: same-day sums are a structuring
     * review, never a CTR (AMLA design §4.6).
     */
    public function isCtrCandidate(int $amountCentavos, string $channel): bool
    {
        return $amountCentavos > $this->ctrThresholdCentavos()
            && in_array($channel, $this->values['ctr_cash_channels'], true);
    }

    public function isToVerify(string $key): bool
    {
        return in_array($key, $this->to_verify, true);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
