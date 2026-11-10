<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\CdaOfficerTrainingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A training an officer took or is scheduled for (CDA design §6). A
 * completed row whose `requirement_key` matches one of the position's
 * required trainings (CdaSetting::required_trainings) counts toward it. Its
 * certificate is a CDA document version with the training as subject.
 * Audited.
 */
class CdaOfficerTraining extends Model
{
    /** @use HasFactory<CdaOfficerTrainingFactory> */
    use Auditable, HasFactory;

    public const STATUSES = ['completed', 'incomplete', 'scheduled', 'expired', 'not_required'];

    protected $fillable = [
        'officer_id', 'title', 'requirement_key', 'training_type', 'is_mandatory', 'provider', 'provider_accreditation_no',
        'started_on', 'completed_on', 'hours', 'certificate_number', 'status', 'expires_on', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'officer_id' => 'integer',
            'is_mandatory' => 'boolean',
            'started_on' => 'date',
            'completed_on' => 'date',
            'hours' => 'decimal:2',
            'expires_on' => 'date',
        ];
    }

    public function officer(): BelongsTo
    {
        return $this->belongsTo(CdaOfficer::class, 'officer_id');
    }
}
