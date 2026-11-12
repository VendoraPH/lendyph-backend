<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\CdaComplianceRequirementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A custom compliance calendar item (gap round B6): a deadline the
 * cooperative tracks beyond the ten CDA report types, for a reporting year.
 * The effective due date is the extension, else the default. Pending items
 * get the deadline reminders; filed and not applicable ones don't. Audited.
 */
class CdaComplianceRequirement extends Model
{
    /** @use HasFactory<CdaComplianceRequirementFactory> */
    use Auditable, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_FILED = 'filed';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_FILED, self::STATUS_NOT_APPLICABLE];

    private const STATUS_WORDS = [self::STATUS_PENDING => 'pending', self::STATUS_FILED => 'filed', self::STATUS_NOT_APPLICABLE => 'not applicable'];

    protected $fillable = [
        'year', 'title', 'description', 'default_due_on', 'extended_due_on', 'reference', 'status', 'filed_on', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'default_due_on' => 'date',
            'extended_due_on' => 'date',
            'filed_on' => 'date',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /** The extension, else the default (YYYY-MM-DD). */
    public function effectiveDueOn(): string
    {
        return ($this->extended_due_on ?? $this->default_due_on)->toDateString();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The description of an update's audit row (the trait describes only updates). */
    public function describeAuditEvent(string $action): ?string
    {
        return $action === 'updated'
            ? "Compliance requirement \"{$this->title}\" ({$this->year}) changed; it is ".self::STATUS_WORDS[$this->status].'.'
            : null;
    }
}
