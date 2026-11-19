<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\OriginationAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable revision of the assessment rules (origination decision 15):
 * the limits, income recognition, product overrides and the periods a year
 * each frequency counts. Saving changed rules adds the next version; the
 * newest is current, and an assessment records the version it used.
 * Decimals are kept as the exact strings entered, never rounded.
 *
 * @property int $version
 * @property array<string, mixed> $rules
 */
class OriginationRuleRevision extends Model
{
    use Auditable, OriginationAppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['version', 'rules', 'created_by'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'rules' => 'array',
            'created_by' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::query()->orderByDesc('version')->firstOrFail();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function describeAuditEvent(string $action): ?string
    {
        return $action === 'created' ? "Assessment rules revision {$this->version} saved" : null;
    }
}
