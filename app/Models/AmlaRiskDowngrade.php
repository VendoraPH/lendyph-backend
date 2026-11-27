<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An override of a subject's AML risk down from High (AMLA-B4 review, built
 * in AMLA-B6): it clears enhanced due diligence, so it waits for a second
 * `amla:cdd` holder, never its requester. Approved, it becomes the
 * requester's override assessment; rejected, the risk stays as it was.
 * `pending_key` holds one waiting request per profile. Kept forever; the one
 * change is its decision, and the UPDATE requires it still pending.
 *
 * @property int $id
 * @property int $profile_id
 * @property string $from_level
 * @property string $to_level
 * @property string $reason
 * @property string $status
 * @property int $requested_by
 * @property int|null $decided_by
 * @property int|null $assessment_id
 */
class AmlaRiskDowngrade extends Model
{
    use AmlaKeptForever;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** @var array<string, string> */
    public const STATUS_WORDS = [self::PENDING => 'Waiting for a second CDD holder', self::APPROVED => 'Approved', self::REJECTED => 'Rejected'];

    private const DECISION_COLUMNS = ['status', 'pending_key', 'decided_by', 'decided_at', 'decision_note', 'assessment_id', 'updated_at'];

    protected $fillable = ['profile_id', 'from_level', 'to_level', 'reason', 'status', 'pending_key', 'requested_by', 'decided_by', 'decided_at', 'decision_note', 'base_assessment_id', 'assessment_id'];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'reason' => 'encrypted',
            'pending_key' => 'integer',
            'requested_by' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'decision_note' => 'encrypted',
            'base_assessment_id' => 'integer',
            'assessment_id' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AmlaProfile::class, 'profile_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    protected function amlaAllowsUpdate(): bool
    {
        return $this->getOriginal('status') === self::PENDING
            && in_array($this->status, [self::APPROVED, self::REJECTED], true)
            && $this->pending_key === null
            && $this->decided_by !== null
            && $this->decided_by !== (int) $this->getOriginal('requested_by')
            && array_diff(array_keys($this->getDirty()), self::DECISION_COLUMNS) === [];
    }

    protected function amlaUpdateConditions(): array
    {
        return ['status' => self::PENDING];
    }
}
