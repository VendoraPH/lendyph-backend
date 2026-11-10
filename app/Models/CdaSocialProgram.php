<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\CdaSocialProgramFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A social program or community activity in a reporting period (CDA design
 * §6): beneficiaries, budget, what was spent and how much came from the
 * Community Development Fund. Feeds the social audit and the ABR. Money is
 * pesos, two-decimal strings. Audited.
 */
class CdaSocialProgram extends Model
{
    /** @use HasFactory<CdaSocialProgramFactory> */
    use Auditable, HasFactory;

    public const CATEGORIES = ['education', 'health', 'environment', 'community', 'youth', 'livelihood', 'disaster_response', 'other'];

    public const FUNDING_SOURCES = ['cdf', 'general_fund', 'cetf', 'external', 'other'];

    public const STATUSES = ['planned', 'ongoing', 'completed', 'cancelled'];

    protected $fillable = [
        'period_id', 'name', 'category', 'description', 'objective', 'held_on', 'ended_on', 'location',
        'target_beneficiaries', 'actual_beneficiaries', 'budget', 'actual_expense', 'funding_source', 'cdf_amount',
        'status', 'responsible_officer_id', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'period_id' => 'integer',
            'held_on' => 'date',
            'ended_on' => 'date',
            'target_beneficiaries' => 'integer',
            'actual_beneficiaries' => 'integer',
            'budget' => 'decimal:2',
            'actual_expense' => 'decimal:2',
            'cdf_amount' => 'decimal:2',
            'responsible_officer_id' => 'integer',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(CdaReportingPeriod::class, 'period_id');
    }

    public function responsibleOfficer(): BelongsTo
    {
        return $this->belongsTo(CdaOfficer::class, 'responsible_officer_id');
    }
}
