<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ask to set a CTR candidate aside as not reportable (AMLA-B6, kept in
 * its own row since AMLA-B7): the reason and who asked, and the decision by a
 * different `amla:approve` holder, a refusal with its own reason. Both texts
 * are encrypted. Asking again is a new row, so no earlier ask is ever
 * overwritten. Kept forever; the one change is its decision, once, and the
 * UPDATE requires it still pending.
 *
 * @property int $id
 * @property int $ctr_candidate_id
 * @property string $reason
 * @property string $status pending|approved|rejected
 * @property int|null $pending_key
 * @property int $requested_by
 * @property int|null $decided_by
 * @property string|null $decision_note
 */
class AmlaCtrSetAside extends Model
{
    use AmlaKeptForever;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    private const DECISION_COLUMNS = ['status', 'pending_key', 'decided_by', 'decided_at', 'decision_note', 'updated_at'];

    protected $fillable = ['ctr_candidate_id', 'reason', 'status', 'pending_key', 'requested_by', 'decided_by', 'decided_at', 'decision_note'];

    protected function casts(): array
    {
        return [
            'ctr_candidate_id' => 'integer',
            'reason' => 'encrypted',
            'pending_key' => 'integer',
            'requested_by' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'decision_note' => 'encrypted',
        ];
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
            && ($this->status === self::APPROVED || filled($this->decision_note))
            && array_diff(array_keys($this->getDirty()), self::DECISION_COLUMNS) === [];
    }

    protected function amlaUpdateConditions(): array
    {
        return ['status' => self::PENDING];
    }
}
