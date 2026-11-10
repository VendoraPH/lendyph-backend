<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\CdaReportingPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One CDA reporting period per calendar year (CDA design §1): Jan 1 to
 * Dec 31, the annual reports' default deadline, an extension CDA granted, and
 * the year-end capture of the loan book (`cda:capture-year-end`). Audited,
 * except the capture itself, which writes its own compact audit row.
 *
 * @property int $year
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon $default_deadline
 * @property Carbon|null $extended_deadline
 * @property array<string, mixed>|null $year_end_capture
 * @property Carbon|null $year_end_captured_at
 */
class CdaReportingPeriod extends Model
{
    /** @use HasFactory<CdaReportingPeriodFactory> */
    use Auditable, HasFactory;

    /** The capture is large and has its own audit row. */
    protected array $auditRedacted = ['year_end_capture'];

    protected $fillable = [
        'year',
        'period_start',
        'period_end',
        'default_deadline',
        'extended_deadline',
        'extension_reference',
        'year_end_capture',
        'year_end_captured_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'default_deadline' => 'date',
            'extended_deadline' => 'date',
            'year_end_capture' => 'array',
            'year_end_captured_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /** The annual reports' deadline: the extension, else the default. */
    public function annualDeadline(): string
    {
        return ($this->extended_deadline ?? $this->default_deadline)->toDateString();
    }

    public function reports(): HasMany
    {
        return $this->hasMany(CdaReport::class, 'period_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
