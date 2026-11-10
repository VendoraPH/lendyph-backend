<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\CdaReportTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A versioned CDA form definition for one report type (CDA design §2): its
 * sections, fields and their sources, calculations, rules, documents and
 * export mapping, in `definition` (TemplateDefinitionValidator). Managed by
 * super_admin only.
 *
 * - `draft` can be edited; `active` is selected for new reports
 *   (TemplateSelector) and is immutable; `retired` is never selected again.
 *   A report keeps the template it was created with.
 * - The model refuses any change to a template that isn't a draft, except
 *   moving it to `active` or `retired` (with `activated_at` or `reason`).
 *
 * @property string $report_type
 * @property string $version
 * @property string $status
 * @property array<string, mixed> $definition
 * @property list<string>|null $cooperative_types
 * @property list<string>|null $asset_classes
 * @property Carbon $effective_from
 * @property Carbon|null $effective_until
 */
class CdaReportTemplate extends Model
{
    /** @use HasFactory<CdaReportTemplateFactory> */
    use Auditable, HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_RETIRED];

    /** What a status change may touch on a template that isn't a draft. */
    private const STATUS_CHANGE_FIELDS = ['status', 'activated_at', 'reason', 'updated_at'];

    protected $fillable = [
        'report_type',
        'version',
        'name',
        'effective_from',
        'effective_until',
        'cooperative_types',
        'asset_classes',
        'definition',
        'excel_template_document_id',
        'status',
        'reason',
        'created_by',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_until' => 'date',
            'cooperative_types' => 'array',
            'asset_classes' => 'array',
            'definition' => 'array',
            'activated_at' => 'datetime',
            'excel_template_document_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CdaReportTemplate $template): void {
            if ($template->getOriginal('status') === self::STATUS_DRAFT) {
                return;
            }

            $changed = array_diff(array_keys($template->getDirty()), self::STATUS_CHANGE_FIELDS);

            if ($changed !== [] || $template->getOriginal('status') === self::STATUS_RETIRED) {
                throw new LogicException("CDA template #{$template->id} is {$template->getOriginal('status')} and can't be changed.");
            }
        });

        static::deleting(function (CdaReportTemplate $template): void {
            if ($template->getOriginal('status') !== self::STATUS_DRAFT) {
                throw new LogicException("CDA template #{$template->id} is {$template->getOriginal('status')} and can't be deleted.");
            }
        });
    }

    public function isVerified(): bool
    {
        return (bool) ($this->definition['meta']['verified'] ?? false);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function excelTemplate(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'excel_template_document_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(CdaReport::class, 'template_id');
    }
}
