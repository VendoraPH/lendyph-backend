<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\CdaMediationCaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A mediation and conciliation case (CDA design §6). Restricted
 * (`cda:view_mediation`), never shown in the member portal, and none of its
 * text reaches an audit row ($auditRedacted): the trail keeps the number,
 * dates, status and meeting count only.
 *
 * - `case_number` is `MC-YYYY-NNNN`, numbered per filing year, given on
 *   create. Create one inside GeneratedNumberWrite::run(...,
 *   GeneratedNumberWrite::MEDIATION_CONFLICT) so two at the same moment
 *   retry instead of failing.
 * - A case has no period: it belongs to each half it overlaps
 *   (scopeOverlapping()).
 *
 * @property string $case_number
 * @property Carbon $filed_on
 * @property Carbon|null $resolved_on
 */
class CdaMediationCase extends Model
{
    /** @use HasFactory<CdaMediationCaseFactory> */
    use Auditable, HasFactory;

    public const NUMBER_PREFIX = 'MC';

    public const STATUSES = ['pending', 'in_mediation', 'resolved', 'escalated', 'withdrawn'];

    /** Every free-text field: parties, the dispute and its outcome. */
    protected array $auditRedacted = ['complainant', 'respondent', 'nature', 'description', 'mediator', 'resolution', 'remarks'];

    protected $fillable = [
        'case_number', 'filed_on', 'complainant', 'respondent', 'nature', 'description', 'mediator',
        'mediation_started_on', 'meetings_count', 'resolution', 'resolved_on', 'status', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'filed_on' => 'date',
            'mediation_started_on' => 'date',
            'resolved_on' => 'date',
            'meetings_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CdaMediationCase $case): void {
            if (filled($case->case_number)) {
                return;
            }

            $year = Carbon::parse($case->filed_on ?? now())->year;
            $prefix = sprintf('%s-%d-', self::NUMBER_PREFIX, $year);
            $last = static::query()->where('case_number', 'like', $prefix.'%')->lockForUpdate()
                ->pluck('case_number')
                ->map(fn (string $number) => (int) substr($number, strlen($prefix)))
                ->max() ?? 0;

            $case->case_number = $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Cases in a half (or any range): filed on or before its end, and not
     * resolved before its start.
     *
     * @param  Builder<CdaMediationCase>  $query
     */
    public function scopeOverlapping(Builder $query, string $from, string $to): void
    {
        $query->whereDate('filed_on', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('resolved_on')->orWhereDate('resolved_on', '>=', $from));
    }
}
