<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A request to release an order (AMLA-B5 review decision: lifting takes two
 * people). One `amla:sanctions` holder asks, with the reason and the lifting
 * order's reference (both kept, the reason encrypted); a different holder
 * approves or rejects it, and the asker may withdraw it. The only change ever
 * written is that one decision, while the stored row is still pending: an
 * approval or rejection is never by the asker, a withdrawal only by the
 * asker. Never deleted.
 *
 * @property int $id
 * @property int $order_id
 * @property string $status pending|approved|rejected|withdrawn
 * @property int|null $pending_key
 * @property string $reason
 * @property string $release_reference
 * @property int $requested_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 */
class AmlaOrderReleaseRequest extends Model
{
    use AmlaKeptForever;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    /** @var array<string, string> */
    public const STATUS_WORDS = [self::PENDING => 'Waiting for approval', self::APPROVED => 'Approved', self::REJECTED => 'Rejected', self::WITHDRAWN => 'Withdrawn'];

    private const DECISION_COLUMNS = ['status', 'pending_key', 'decided_by', 'decided_at', 'decision_note', 'updated_at'];

    protected $fillable = ['order_id', 'status', 'pending_key', 'reason', 'release_reference', 'requested_by', 'decided_by', 'decided_at', 'decision_note'];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'pending_key' => 'integer',
            'reason' => 'encrypted',
            'requested_by' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'decision_note' => 'encrypted',
        ];
    }

    /** Only its one decision; never approved or rejected by the asker, only withdrawn by them. */
    protected function amlaAllowsUpdate(): bool
    {
        if ($this->getOriginal('status') !== self::PENDING
            || $this->decided_by === null
            || array_diff(array_keys($this->getDirty()), self::DECISION_COLUMNS) !== []) {
            return false;
        }

        $byAsker = (int) $this->decided_by === (int) $this->getOriginal('requested_by');

        return match ($this->status) {
            self::APPROVED, self::REJECTED => ! $byAsker,
            self::WITHDRAWN => $byAsker,
            default => false,
        };
    }

    /** @return array<string, mixed> */
    protected function amlaUpdateConditions(): array
    {
        return ['status' => self::PENDING];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(AmlaOrder::class, 'order_id');
    }
}
